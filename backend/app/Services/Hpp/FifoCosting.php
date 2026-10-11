<?php

namespace App\Services\Hpp;

use App\Models\Hpp\HppPurchaseItem;

/**
 * FIFO (Pro): every purchase line is a lot (remaining_qty). Usage takes from the oldest lot first,
 * and the ingredient's current cost is the oldest lot still in stock.
 */
final class FifoCosting
{
    /** Cost per use unit of the oldest lot with stock left, or null when no lot has stock. */
    public function currentCost(int $ingredientId): ?float
    {
        $lot = $this->lots($ingredientId)->first();

        return $lot ? (float) $lot->unit_cost : null;
    }

    /** Take $qty (use units) from the oldest lots. Returns the cost of what was taken. */
    public function consume(int $ingredientId, float $qty): float
    {
        $left = $qty;
        $cost = 0.0;
        foreach ($this->lots($ingredientId, true) as $lot) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, (float) $lot->remaining_qty);
            HppPurchaseItem::query()->whereKey($lot->id)->update(['remaining_qty' => round((float) $lot->remaining_qty - $take, 3)]);
            $cost += $take * (float) $lot->unit_cost;
            $left -= $take;
        }

        return $cost;
    }

    private function lots(int $ingredientId, bool $lock = false)
    {
        return HppPurchaseItem::query()->where('hpp_purchase_items.ingredient_id', $ingredientId)->where('hpp_purchase_items.remaining_qty', '>', 0)
            ->join('hpp_purchases', 'hpp_purchases.id', '=', 'hpp_purchase_items.purchase_id')
            ->orderBy('hpp_purchases.purchased_at')->orderBy('hpp_purchase_items.id')
            ->select('hpp_purchase_items.*')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
    }
}
