<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Product snapshot of a landing order line (name and price at purchase time). */
class LandingOrderItem extends Model
{
    protected $fillable = ['order_id', 'block_id', 'product_kind', 'product_name', 'unit_price', 'qty', 'line_total', 'snapshot'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'qty' => 'integer',
            'line_total' => 'integer',
            'snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LandingPageOrder::class, 'order_id');
    }
}
