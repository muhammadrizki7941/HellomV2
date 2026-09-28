<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosMember extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'organization_id',
        'name',
        'phone',
        'phone_normalized',
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
        'merged_at' => 'datetime',
    ];

    /**
     * Members belong to the organization (every outlet sees the same member).
     * Merged-away duplicates are excluded.
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId)->whereNull('merged_into_id');
    }

    /** Points ledger (Fase 2B). The old pos_point_transactions table is kept read-only. */
    public function ledger(): HasMany
    {
        return $this->hasMany(MemberPointTransaction::class, 'member_id');
    }

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