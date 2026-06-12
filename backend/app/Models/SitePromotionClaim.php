<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitePromotionClaim extends Model
{
    protected $fillable = [
        'tenant_id',
        'site_promotion_id',
        'pos_member_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'claim_code',
        'bonus_points_awarded',
        'claimed_via',
        'metadata',
    ];

    protected $casts = [
        'bonus_points_awarded' => 'integer',
        'metadata' => 'array',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(SitePromotion::class, 'site_promotion_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(PosMember::class, 'pos_member_id');
    }
}
