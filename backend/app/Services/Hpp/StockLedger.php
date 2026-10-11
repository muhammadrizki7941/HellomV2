<?php

namespace App\Services\Hpp;

use App\Models\Hpp\HppStockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Ingredient stock: every change is a movement (purchase, sale, opname, adjust, waste) and the
 * running quantity per ingredient per outlet (0 = no outlet) lives in hpp_ingredient_stocks.
 * Call inside the caller's transaction.
 */
final class StockLedger
{
    public function move(int $organizationId, int $ingredientId, int $outletId, float $qty, string $type, float $unitCost = 0.0, ?string $refType = null, ?int $refId = null, ?string $note = null): HppStockMovement
    {
        $movement = HppStockMovement::query()->create([
            'organization_id' => $organizationId, 'ingredient_id' => $ingredientId, 'outlet_id' => $outletId, 'type' => $type,
            'qty' => round($qty, 3), 'unit_cost' => round($unitCost, 4), 'ref_type' => $refType, 'ref_id' => $refId,
            'note' => $note !== null ? mb_substr($note, 0, 160) : null, 'created_at' => now(),
        ]);
        DB::statement(
            'INSERT INTO hpp_ingredient_stocks (ingredient_id, outlet_id, qty, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at = VALUES(updated_at)',
            [$ingredientId, $outletId, round($qty, 3), now(), now()]
        );

        return $movement;
    }

    /** Stock of one ingredient: one outlet, or all outlets together (null). */
    public function qty(int $ingredientId, ?int $outletId = null): float
    {
        return (float) DB::table('hpp_ingredient_stocks')->where('ingredient_id', $ingredientId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))->sum('qty');
    }

    /** @return array<int, float> ingredient_id => qty (optionally one outlet). */
    public function all(array $ingredientIds, ?int $outletId = null): array
    {
        return DB::table('hpp_ingredient_stocks')->whereIn('ingredient_id', $ingredientIds ?: [0])
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->selectRaw('ingredient_id, SUM(qty) as qty')->groupBy('ingredient_id')->pluck('qty', 'ingredient_id')
            ->map(fn ($v) => (float) $v)->all();
    }
}
