<?php

namespace App\Models\Hpp;

use Illuminate\Database\Eloquent\Model;

/** Raw material or packaging. cost_per_unit is per use unit (gram/ml/pcs), before yield loss. */
class HppIngredient extends Model
{
    protected $table = 'hpp_ingredients';

    protected $guarded = ['id'];

    protected $casts = [
        'buy_factor' => 'float',
        'last_buy_price' => 'integer',
        'cost_per_unit' => 'float',
        'yield_pct' => 'float',
        'is_archived' => 'boolean',
    ];

    /** Cost per usable unit: a 1 kg chicken that yields 800 g costs more per usable gram. */
    public function effectiveCost(?float $costPerUnit = null): float
    {
        $yield = max(1.0, min(100.0, (float) $this->yield_pct));

        return (float) ($costPerUnit ?? $this->cost_per_unit) * 100 / $yield;
    }
}
