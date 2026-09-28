<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only points ledger. The member's balance is SUM(points); pos_members.redeemable_points
 * is only a cache of that sum (see LoyaltyService / pos:points:reconcile).
 * Positive rows (earn/adjust/reversal +) keep `remaining_points` so redemptions and
 * expiry consume the oldest points first.
 */
class MemberPointTransaction extends Model
{
    public const TYPE_EARN = 'earn';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_ADJUST = 'adjust';
    public const TYPE_EXPIRE = 'expire';
    public const TYPE_REVERSAL = 'reversal';

    public const LABELS = [
        self::TYPE_EARN => 'Poin didapat',
        self::TYPE_REDEEM => 'Poin ditukar',
        self::TYPE_ADJUST => 'Penyesuaian',
        self::TYPE_EXPIRE => 'Poin kedaluwarsa',
        self::TYPE_REVERSAL => 'Pembatalan poin',
    ];

    protected $fillable = [
        'organization_id',
        'member_id',
        'outlet_id',
        'order_id',
        'type',
        'points',
        'balance_after',
        'remaining_points',
        'expires_at',
        'reason',
        'user_id',
        'reverses_id',
        'idempotency_key',
        'metadata',
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_after' => 'integer',
        'remaining_points' => 'integer',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(PosMember::class, 'member_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
