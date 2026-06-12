<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosMember extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'email',
        'total_points',
        'total_orders',
        'total_spent',
        'redeemable_points',
        'last_order_at',
    ];

    protected $casts = [
        'total_points' => 'integer',
        'total_orders' => 'integer',
        'total_spent' => 'integer',
        'redeemable_points' => 'integer',
        'last_order_at' => 'datetime',
    ];

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PosPointTransaction::class, 'member_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PosRedemption::class, 'member_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'member_id');
    }

    public function getTierAttribute(): string
    {
        if ($this->total_orders >= 20) return 'VIP';
        if ($this->total_orders >= 5) return 'Reguler';
        return 'Baru';
    }

    public function getTierEmojiAttribute(): string
    {
        return match($this->tier) {
            'VIP' => '👑',
            'Reguler' => '⭐',
            'Baru' => '🆕',
        };
    }
}