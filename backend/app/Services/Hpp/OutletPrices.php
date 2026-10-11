<?php

namespace App\Services\Hpp;

use Illuminate\Support\Facades\DB;

/**
 * Pro: an ingredient can cost differently per outlet (other supplier/city). Stored per outlet;
 * the cost engine uses them as overrides when an outlet is chosen.
 */
final class OutletPrices
{
    public static function set(int $ingredientId, int $outletId, float $costPerUnit): void
    {
        DB::statement(
            'INSERT INTO hpp_ingredient_prices (ingredient_id, outlet_id, cost_per_unit, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE cost_per_unit = VALUES(cost_per_unit), updated_at = VALUES(updated_at)',
            [$ingredientId, $outletId, round($costPerUnit, 4), now(), now()]
        );
    }

    public static function clear(int $ingredientId, int $outletId): void
    {
        DB::table('hpp_ingredient_prices')->where('ingredient_id', $ingredientId)->where('outlet_id', $outletId)->delete();
    }

    /** @return array<int, float> ingredient_id => cost per use unit for this outlet */
    public static function forOutlet(int $organizationId, int $outletId): array
    {
        return DB::table('hpp_ingredient_prices')
            ->join('hpp_ingredients', 'hpp_ingredients.id', '=', 'hpp_ingredient_prices.ingredient_id')
            ->where('hpp_ingredients.organization_id', $organizationId)->where('hpp_ingredient_prices.outlet_id', $outletId)
            ->pluck('hpp_ingredient_prices.cost_per_unit', 'hpp_ingredient_prices.ingredient_id')
            ->map(fn ($v) => (float) $v)->all();
    }
}
