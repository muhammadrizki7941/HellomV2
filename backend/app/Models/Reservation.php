<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'reservation_space_id',
        'space_name',
        'items_snapshot',
        'menu_order_snapshot',
        'customer_name',
        'customer_phone',
        'customer_email',
        'scheduled_at',
        'duration_minutes',
        'guests_count',
        'notes',
        'admin_notes',
        'rent_price',
        'items_total',
        'menu_commitment_total',
        'total_price',
        'status',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'duration_minutes' => 'integer',
        'guests_count' => 'integer',
        'rent_price' => 'integer',
        'items_total' => 'integer',
        'menu_commitment_total' => 'integer',
        'total_price' => 'integer',
        'items_snapshot' => 'array',
        'menu_order_snapshot' => 'array',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(ReservationSpace::class, 'reservation_space_id');
    }
}
