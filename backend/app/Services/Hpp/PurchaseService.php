<?php

namespace App\Services\Hpp;

use App\Jobs\RecalculateHppCosts;
use App\Models\Hpp\HppIngredient;
use App\Models\Hpp\HppPurchase;
use App\Models\Hpp\HppPurchaseItem;
use App\Models\Hpp\HppSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Belanja bahan: the one place ingredient prices change automatically. Each line adds stock and
 * sets the ingredient cost by the shop's method — last price, weighted average, or FIFO (Pro: the
 * oldest lot still in stock). Then every product's HPP is recalculated in the background.
 */
final class PurchaseService
{
    public function __construct(private readonly StockLedger $stock, private readonly FifoCosting $fifo)
    {
    }

    /**
     * @param array{purchased_at: string, supplier?: ?string, outlet_id?: ?int, notes?: ?string, items: list<array{ingredient_id: int, qty: float, total_price: int}>} $data
     * @return array{purchase: HppPurchase, changes: list<array<string, mixed>>}
     */
    public function record(int $organizationId, ?int $userId, array $data): array
    {
        $method = (string) HppSetting::for($organizationId)->price_method;
        $outletId = (int) ($data['outlet_id'] ?? 0);

        $result = DB::transaction(function () use ($organizationId, $userId, $data, $method, $outletId) {
            $ids = array_map(fn ($i) => (int) $i['ingredient_id'], $data['items']);
            $ingredients = HppIngredient::query()->where('organization_id', $organizationId)->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            foreach ($data['items'] as $i => $item) {
                if (!isset($ingredients[(int) $item['ingredient_id']])) {
                    throw ValidationException::withMessages(["items.{$i}.ingredient_id" => 'Bahan tidak ditemukan.']);
                }
            }

            $purchase = HppPurchase::query()->create([
                'organization_id' => $organizationId, 'outlet_id' => $outletId, 'purchased_at' => $data['purchased_at'],
                'supplier' => $data['supplier'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $userId,
                'total' => array_sum(array_map(fn ($i) => (int) $i['total_price'], $data['items'])),
            ]);

            $changes = [];
            foreach ($data['items'] as $item) {
                /** @var HppIngredient $ingredient */
                $ingredient = $ingredients[(int) $item['ingredient_id']];
                $qty = (float) $item['qty'];
                $qtyUse = $qty * (float) $ingredient->buy_factor;
                $unitCost = $qtyUse > 0 ? (int) $item['total_price'] / $qtyUse : 0.0;
                $previous = (float) $ingredient->cost_per_unit;
                $stockBefore = max(0.0, $this->stock->qty((int) $ingredient->id));

                HppPurchaseItem::query()->create([
                    'purchase_id' => $purchase->id, 'ingredient_id' => $ingredient->id, 'qty' => $qty, 'total_price' => (int) $item['total_price'],
                    'qty_use' => $qtyUse, 'unit_cost' => round($unitCost, 4), 'remaining_qty' => $qtyUse, 'previous_cost' => $previous,
                ]);
                $this->stock->move($organizationId, (int) $ingredient->id, $outletId, $qtyUse, 'purchase', $unitCost, 'purchase', (int) $purchase->id);

                $newCost = match ($method) {
                    'average' => $stockBefore + $qtyUse > 0 ? ($stockBefore * $previous + $qtyUse * $unitCost) / ($stockBefore + $qtyUse) : $unitCost,
                    'fifo' => $this->fifo->currentCost((int) $ingredient->id) ?? $unitCost,
                    default => $unitCost,
                };
                $ingredient->forceFill([
                    'cost_per_unit' => round($newCost, 4),
                    'last_buy_price' => $qty > 0 ? (int) round((int) $item['total_price'] / $qty) : (int) $ingredient->last_buy_price,
                ])->save();
                $changes[] = [
                    'ingredient_id' => (int) $ingredient->id, 'name' => (string) $ingredient->name, 'unit' => (string) $ingredient->use_unit,
                    'previous_cost' => round($previous, 4), 'new_cost' => round($newCost, 4),
                    'change_pct' => $previous > 0 ? round(($newCost - $previous) / $previous * 100, 1) : null,
                ];
            }

            return ['purchase' => $purchase, 'changes' => $changes];
        });

        RecalculateHppCosts::dispatch($organizationId);

        return $result;
    }
}
