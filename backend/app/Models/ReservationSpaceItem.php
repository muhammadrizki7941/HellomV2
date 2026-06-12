<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationSpaceItem extends Model
{
    protected $fillable = [
        'reservation_space_id',
        'product_id',
        'product_name',
        'unit_price',
        'qty',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'unit_price' => 'integer',
        'qty' => 'integer',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
        'product_id' => 'integer',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(ReservationSpace::class, 'reservation_space_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getLineTotalAttribute(): int
    {
        return (int) $this->qty * (int) $this->unit_price;
    }
}
