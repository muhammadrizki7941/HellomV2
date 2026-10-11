<?php

namespace App\Models\Hpp;

use Illuminate\Database\Eloquent\Model;

/** Ingredient stock in/out (use units): purchase, sale (POS backflush), opname, adjust, waste. */
class HppStockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'hpp_stock_movements';

    protected $guarded = ['id'];

    protected $casts = ['qty' => 'float', 'unit_cost' => 'float', 'outlet_id' => 'integer', 'ref_id' => 'integer'];
}
