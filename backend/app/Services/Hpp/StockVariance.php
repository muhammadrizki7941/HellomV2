<?php

namespace App\Services\Hpp;

use App\Models\Hpp\HppIngredient;
use App\Models\Hpp\HppStockCount;
use App\Models\Hpp\HppStockCountItem;
use App\Models\Hpp\HppStockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pro: stok opname and HPP teoritis vs aktual. Between two counts of an outlet:
 *
 *   pemakaian teoritis = what POS sales used by recipe (backflush movements)
 *   pemakaian aktual   = stok awal (opname) + pembelian − stok akhir (opname)
 *   selisih            = aktual − teoritis  (> 0: bocor, boros, porsi kebesaran, atau kecurangan)
 *
 * Saving a count also corrects the system stock to what was counted (movement "opname").
 */
final class StockVariance
{
    public const FLAG_PCT = 5.0;

    public function __construct(private readonly StockLedger $stock)
    {
    }

    /** @param list<array{ingredient_id: int, counted_qty: float}> $items */
    public function count(int $organizationId, int $outletId, ?int $userId, array $items, ?string $notes = null): HppStockCount
    {
        return DB::transaction(function () use ($organizationId, $outletId, $userId, $items, $notes) {
            $ingredients = HppIngredient::query()->where('organization_id', $organizationId)
                ->whereIn('id', array_map(fn ($i) => (int) $i['ingredient_id'], $items))->get()->keyBy('id');
            foreach ($items as $i => $item) {
                if (!isset($ingredients[(int) $item['ingredient_id']])) {
                    throw ValidationException::withMessages(["items.{$i}.ingredient_id" => 'Bahan tidak ditemukan.']);
                }
            }
            $count = HppStockCount::query()->create(['organization_id' => $organizationId, 'outlet_id' => $outletId, 'counted_at' => now(), 'notes' => $notes, 'created_by' => $userId]);
            foreach ($items as $item) {
                $ingredient = $ingredients[(int) $item['ingredient_id']];
                $system = $this->stock->qty((int) $ingredient->id, $outletId);
                $diff = (float) $item['counted_qty'] - $system;
                HppStockCountItem::query()->create([
                    'count_id' => $count->id, 'ingredient_id' => $ingredient->id, 'system_qty' => round($system, 3),
                    'counted_qty' => (float) $item['counted_qty'], 'diff' => round($diff, 3), 'unit_cost' => (float) $ingredient->cost_per_unit,
                ]);
                if (abs($diff) >= 0.001) {
                    $this->stock->move($organizationId, (int) $ingredient->id, $outletId, $diff, 'opname', (float) $ingredient->cost_per_unit, 'stock_count', (int) $count->id, 'Stok opname');
                }
            }

            return $count;
        });
    }

    /**
     * Variance between two counts (default: the last two of the outlet).
     *
     * @return array{from: ?array, to: ?array, rows: list<array<string, mixed>>, total_value: float}
     */
    public function report(int $organizationId, int $outletId, ?int $fromId = null, ?int $toId = null): array
    {
        $counts = HppStockCount::query()->where('organization_id', $organizationId)->where('outlet_id', $outletId)->orderByDesc('counted_at')->orderByDesc('id');
        $to = $toId ? (clone $counts)->find($toId) : (clone $counts)->first();
        $from = $fromId ? (clone $counts)->find($fromId) : ($to ? (clone $counts)->where('counted_at', '<=', $to->counted_at)->whereKeyNot($to->id)->first() : null);
        if (!$from || !$to) {
            return ['from' => $from ? $this->presentCount($from) : null, 'to' => $to ? $this->presentCount($to) : null, 'rows' => [], 'total_value' => 0.0];
        }
        if ($from->counted_at->gt($to->counted_at)) {
            [$from, $to] = [$to, $from];
        }
        $opening = $from->items()->pluck('counted_qty', 'ingredient_id')->map(fn ($v) => (float) $v);
        $closing = $to->items()->pluck('counted_qty', 'ingredient_id')->map(fn ($v) => (float) $v);
        $moves = HppStockMovement::query()->where('organization_id', $organizationId)->where('outlet_id', $outletId)
            ->where('created_at', '>', $from->counted_at)->where('created_at', '<=', $to->counted_at)->whereIn('type', ['purchase', 'sale', 'waste'])
            ->selectRaw('ingredient_id, type, SUM(qty) as qty')->groupBy('ingredient_id', 'type')->get();
        $ingredients = HppIngredient::query()->where('organization_id', $organizationId)
            ->whereIn('id', $closing->keys()->merge($opening->keys())->unique()->all() ?: [0])->get()->keyBy('id');

        $rows = [];
        $total = 0.0;
        foreach ($ingredients as $id => $ingredient) {
            if (!$closing->has($id) || !$opening->has($id)) {
                continue;
            }
            $purchased = (float) $moves->where('ingredient_id', $id)->where('type', 'purchase')->sum('qty');
            $theoretical = -(float) $moves->where('ingredient_id', $id)->whereIn('type', ['sale', 'waste'])->sum('qty');
            $actual = $opening[$id] + $purchased - $closing[$id];
            $variance = $actual - $theoretical;
            $value = $variance * $ingredient->effectiveCost();
            $pct = $theoretical > 0 ? $variance / $theoretical * 100 : null;
            $total += $value;
            $rows[] = [
                'ingredient_id' => (int) $id, 'name' => (string) $ingredient->name, 'unit' => (string) $ingredient->use_unit,
                'opening' => round($opening[$id], 3), 'purchased' => round($purchased, 3), 'closing' => round($closing[$id], 3),
                'theoretical' => round($theoretical, 3), 'actual' => round($actual, 3), 'variance' => round($variance, 3),
                'variance_pct' => $pct !== null ? round($pct, 1) : null, 'value' => round($value, 2),
                'flag' => $variance > 0.0005 && ($pct === null || $pct > self::FLAG_PCT),
            ];
        }
        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);

        return ['from' => $this->presentCount($from), 'to' => $this->presentCount($to), 'rows' => $rows, 'total_value' => round($total, 2)];
    }

    /** @return array<string, mixed> */
    public function presentCount(HppStockCount $count): array
    {
        return ['id' => (int) $count->id, 'counted_at' => $count->counted_at->toIso8601String(), 'outlet_id' => (int) $count->outlet_id ?: null,
            'notes' => $count->notes, 'items' => (int) $count->items()->count()];
    }
}
