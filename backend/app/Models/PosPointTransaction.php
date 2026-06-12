<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosPointTransaction extends Model
{
    protected $fillable = [
        'tenant_id',
        'member_id',
        'order_id',
        'type',
        'points',
        'balance_after',
        'description',
        'metadata',
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_after' => 'integer',
        'metadata' => 'array',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(PosMember::class, 'member_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'earn' => 'Poin didapat',
            'redeem' => 'Poin ditukar',
            'expire' => 'Poin expired',
            'bonus' => 'Bonus poin',
            'manual' => 'Manual',
            default => $this->type,
        };
    }

    public function getTypeColorAttribute(): string
    {
        return match($this->type) {
            'earn' => 'text-green-600',
            'redeem' => 'text-red-600',
            'expire' => 'text-orange-600',
            'bonus' => 'text-blue-600',
            'manual' => 'text-gray-600',
            default => 'text-gray-600',
        };
    }
}