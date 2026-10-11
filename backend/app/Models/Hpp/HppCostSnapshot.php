<?php

namespace App\Models\Hpp;

use Illuminate\Database\Eloquent\Model;

/** HPP per product per day (trend charts, historical reports). */
class HppCostSnapshot extends Model
{
    protected $table = 'hpp_cost_snapshots';

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot_date' => 'date', 'cost_material' => 'float', 'cost_packaging' => 'float', 'cost_full' => 'float',
        'selling_price' => 'integer', 'margin_pct' => 'float',
    ];
}
