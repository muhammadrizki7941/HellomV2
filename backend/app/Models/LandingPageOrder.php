<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPageOrder extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'landing_page_id',
        'block_id',
        'product_kind',
        'product_name',
        'amount',
        'commission_amount',
        'net_amount',
        'buyer_name',
        'buyer_email',
        'buyer_phone',
        'status',
        'provider',
        'reference_id',
        'gateway_ref',
        'download_token',
        'file_url',
        'settlement_eta',
        'paid_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'commission_amount' => 'integer',
            'net_amount' => 'integer',
            'settlement_eta' => 'datetime',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isPaid(): bool
    {
        return (string) $this->status === self::STATUS_PAID;
    }
}
