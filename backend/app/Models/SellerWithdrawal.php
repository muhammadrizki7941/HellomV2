<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Withdrawal from a seller's sales balance (see App\Services\SellerFinance\WithdrawalService). */
class SellerWithdrawal extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const OPEN_STATUSES = [self::STATUS_REQUESTED, self::STATUS_PROCESSING];

    public const LABELS = [
        self::STATUS_REQUESTED => 'Diajukan',
        self::STATUS_PROCESSING => 'Diproses',
        self::STATUS_PAID => 'Berhasil',
        self::STATUS_FAILED => 'Gagal',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    protected $fillable = [
        'organization_id', 'requested_by_user_id', 'reviewed_by_user_id', 'status', 'amount', 'fee_amount', 'net_amount',
        'destination_type', 'bank_code', 'bank_name', 'account_number', 'account_name', 'mode', 'provider', 'reference',
        'provider_ref', 'proof_path', 'failure_reason', 'notes', 'processing_at', 'paid_at', 'failed_at', 'cancelled_at',
        'sla_warned_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'net_amount' => 'integer',
            'processing_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'sla_warned_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isOpen(): bool
    {
        return in_array((string) $this->status, self::OPEN_STATUSES, true);
    }
}
