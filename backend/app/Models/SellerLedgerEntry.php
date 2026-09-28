<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only seller ledger row. Never updated or deleted. */
class SellerLedgerEntry extends Model
{
    public const TYPE_SALE = 'sale';
    public const TYPE_PLATFORM_FEE = 'platform_fee';
    public const TYPE_GATEWAY_FEE = 'gateway_fee';
    public const TYPE_RELEASE = 'release';
    public const TYPE_WITHDRAWAL = 'withdrawal';
    public const TYPE_WITHDRAWAL_REVERSAL = 'withdrawal_reversal';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_OPENING = 'opening';

    public const BUCKET_PENDING = 'pending';
    public const BUCKET_AVAILABLE = 'available';

    public const LABELS = [
        self::TYPE_SALE => 'Penjualan',
        self::TYPE_PLATFORM_FEE => 'Biaya layanan Hellom',
        self::TYPE_GATEWAY_FEE => 'Biaya pembayaran',
        self::TYPE_RELEASE => 'Saldo cair',
        self::TYPE_WITHDRAWAL => 'Penarikan dana',
        self::TYPE_WITHDRAWAL_REVERSAL => 'Penarikan dibatalkan / gagal',
        self::TYPE_REFUND => 'Refund',
        self::TYPE_ADJUSTMENT => 'Penyesuaian',
        self::TYPE_OPENING => 'Saldo awal',
    ];

    public const UPDATED_AT = null;

    protected $table = 'seller_balance_ledger';

    protected $fillable = [
        'organization_id', 'type', 'bucket', 'amount', 'pending_after', 'available_after', 'order_id', 'withdrawal_id',
        'group_key', 'idempotency_key', 'available_at', 'description', 'metadata', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'pending_after' => 'integer',
            'available_after' => 'integer',
            'available_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('seller_balance_ledger is append-only.'));
        static::deleting(fn () => throw new LogicException('seller_balance_ledger is append-only.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LandingPageOrder::class, 'order_id');
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(SellerWithdrawal::class, 'withdrawal_id');
    }
}
