<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One booked time of a rental product (App\Services\Landing\BookingService). starts_at/ends_at are
 * WIB wall-clock times; read them with ->format(), never convert their timezone.
 */
class LandingBooking extends Model
{
    public const STATUS_HELD = 'held';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['organization_id', 'product_id', 'order_id', 'starts_at', 'ends_at', 'units', 'status', 'held_until', 'confirmed_at', 'reminded_at'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'held_until' => 'datetime',
        'confirmed_at' => 'datetime',
        'reminded_at' => 'datetime',
        'units' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(LandingProduct::class, 'product_id')->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LandingPageOrder::class, 'order_id');
    }
}
