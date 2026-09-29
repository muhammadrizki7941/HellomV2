<?php

namespace App\Models;

use App\Support\FrontendUrl;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A buyer's order on a seller's landing page. Status changes go through
 * App\Services\SellerFinance\LandingPaymentService (never from the client):
 *
 *   pending → paid → fulfilled
 *   pending → expired | failed      (a verified late payment may still move these to paid)
 *   paid | fulfilled → refunded
 */
class LandingPageOrder extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_PAID, self::STATUS_EXPIRED, self::STATUS_FAILED],
        self::STATUS_EXPIRED => [self::STATUS_PAID],
        self::STATUS_FAILED => [self::STATUS_PAID],
        self::STATUS_PAID => [self::STATUS_FULFILLED, self::STATUS_REFUNDED],
        self::STATUS_FULFILLED => [self::STATUS_REFUNDED],
        self::STATUS_REFUNDED => [],
    ];

    public const LABELS = [
        self::STATUS_PENDING => 'Menunggu pembayaran',
        self::STATUS_PAID => 'Lunas',
        self::STATUS_FULFILLED => 'Terkirim',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
        self::STATUS_FAILED => 'Gagal',
        self::STATUS_REFUNDED => 'Dikembalikan',
    ];

    protected $fillable = [
        'organization_id',
        'landing_page_id',
        'block_id',
        'product_kind',
        'product_name',
        'amount',
        'commission_amount',
        'gateway_fee_amount',
        'net_amount',
        'paid_amount',
        'buyer_name',
        'buyer_email',
        'buyer_phone',
        'status',
        'provider',
        'payment_method',
        'payment_channel',
        'reference_id',
        'gateway_ref',
        'gateway_trx_id',
        'download_token',
        'file_url',
        'settlement_eta',
        'paid_at',
        'expires_at',
        'expired_at',
        'failed_at',
        'fulfilled_at',
        'refunded_at',
        'ledger_posted_at',
        'metadata',
        'product_id',
        'quantity',
        'subtotal_amount',
        'discount_amount',
        'shipping_amount',
        'coupon_id',
        'coupon_code',
        'shipping_address',
        'custom_fields',
        'access_max_opens',
        'access_days',
        'download_limit',
        'shipping_courier',
        'tracking_number',
        'shipped_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'commission_amount' => 'integer',
            'gateway_fee_amount' => 'integer',
            'net_amount' => 'integer',
            'paid_amount' => 'integer',
            'settlement_eta' => 'datetime',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'expired_at' => 'datetime',
            'failed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'ledger_posted_at' => 'datetime',
            'metadata' => 'array',
            'quantity' => 'integer',
            'subtotal_amount' => 'integer',
            'discount_amount' => 'integer',
            'shipping_amount' => 'integer',
            'shipping_address' => 'array',
            'custom_fields' => 'array',
            'access_max_opens' => 'integer',
            'access_days' => 'integer',
            'access_open_count' => 'integer',
            'access_last_opened_at' => 'datetime',
            'download_limit' => 'integer',
            'download_count' => 'integer',
            'shipped_at' => 'datetime',
            'inventory_reserved_at' => 'datetime',
            'inventory_released_at' => 'datetime',
            'emails_sent_at' => 'datetime',
            'email_resend_count' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LandingProduct::class, 'product_id')->withTrashed();
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(LandingCoupon::class, 'coupon_id')->withTrashed();
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(LandingRefund::class, 'order_id');
    }

    /** Buyer's access page (/akses/{token}); the token exists once the order is paid. */
    public function accessUrl(): ?string
    {
        return $this->download_token ? FrontendUrl::to('/akses/' . $this->download_token) : null;
    }

    public function accessExpiresAt(): ?CarbonInterface
    {
        return $this->access_days && $this->paid_at ? $this->paid_at->copy()->addDays((int) $this->access_days) : null;
    }

    public function items(): HasMany
    {
        return $this->hasMany(LandingOrderItem::class, 'order_id');
    }

    /** Money received: paid, fulfilled (and refunded orders were paid once). */
    public function isPaid(): bool
    {
        return in_array((string) $this->status, [self::STATUS_PAID, self::STATUS_FULFILLED], true);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[(string) $this->status] ?? [], true);
    }
}
