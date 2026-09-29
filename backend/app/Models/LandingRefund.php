<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Refund of a landing order to the buyer. The amount leaves the seller balance when the
 * seller asks for it; Hellom transfers it to the buyer (manual, like withdrawals).
 *   requested → paid     (order becomes refunded)
 *   requested → failed   (money back to the seller balance)
 */
class LandingRefund extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';

    public const LABELS = [
        self::STATUS_REQUESTED => 'Diproses',
        self::STATUS_PAID => 'Sudah dikembalikan',
        self::STATUS_FAILED => 'Gagal',
    ];

    protected $fillable = [
        'organization_id', 'order_id', 'requested_by_user_id', 'reviewed_by_user_id', 'reference', 'status', 'amount', 'reason',
        'destination_type', 'bank_code', 'bank_name', 'account_number', 'account_name', 'proof_path', 'failure_reason', 'paid_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LandingPageOrder::class, 'order_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
