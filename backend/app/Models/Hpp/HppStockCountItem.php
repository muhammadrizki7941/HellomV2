<?php

namespace App\Models\Hpp;

use Illuminate\Database\Eloquent\Model;

class HppStockCountItem extends Model
{
    protected $table = 'hpp_stock_count_items';

    protected $guarded = ['id'];

    protected $casts = ['system_qty' => 'float', 'counted_qty' => 'float', 'diff' => 'float', 'unit_cost' => 'float'];
}
