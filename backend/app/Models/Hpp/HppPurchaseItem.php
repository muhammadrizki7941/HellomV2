<?php

namespace App\Models\Hpp;

use Illuminate\Database\Eloquent\Model;

/** One purchased ingredient; also a FIFO lot (remaining_qty) for the Pro price method. */
class HppPurchaseItem extends Model
{
    protected $table = 'hpp_purchase_items';

    protected $guarded = ['id'];

    protected $casts = [
        'qty' => 'float', 'total_price' => 'integer', 'qty_use' => 'float', 'unit_cost' => 'float',
        'remaining_qty' => 'float', 'previous_cost' => 'float',
    ];
}
