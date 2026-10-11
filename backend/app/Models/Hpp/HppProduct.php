<?php

namespace App\Models\Hpp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A product (or sub-recipe) whose cost is calculated: Mode Cepat (batch ÷ yield) or a recipe. */
class HppProduct extends Model
{
    protected $table = 'hpp_products';

    protected $guarded = ['id'];

    protected $casts = [
        'is_subrecipe' => 'boolean',
        'selling_price' => 'integer',
        'target_margin_pct' => 'float',
        'yield_qty' => 'float',
        'quick_batch_cost' => 'integer',
        'labor_minutes' => 'integer',
        'other_cost' => 'integer',
        'channel_prices' => 'array',
        'cost_material' => 'float',
        'cost_packaging' => 'float',
        'cost_full' => 'float',
        'cost_updated_at' => 'datetime',
        'is_archived' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(HppRecipeItem::class, 'product_id')->orderBy('sort_order')->orderBy('id');
    }
}
