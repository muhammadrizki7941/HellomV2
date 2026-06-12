<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosRedemption extends Model
{
    protected $fillable = [
        'tenant_id',
        'member_id',
        'order_id',
        'reward_rule_id',
        'points_used',
        'discount_amount',
        'status',
    ];

    protected $casts = [
        'points_used' => 'integer',
        'discount_amount' => 'integer',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(PosMember::class, 'member_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function rewardRule(): BelongsTo
    {
        return $this->belongsTo(PosRewardRule::class, 'reward_rule_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Menunggu',
            'applied' => 'Diterapkan',
            'cancelled' => 'Dibatalkan',
            default => $this->status,
        };
    }
}