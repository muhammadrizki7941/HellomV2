<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformPayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'status',
        'currency',
        'amount',
        'fee',
        'net_amount',
        'bank_code',
        'account_number',
        'account_holder_name',
        'account_number_masked',
        'failure_code',
        'failure_reason',
        'metadata',
        'processed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_FAILED, self::STATUS_CANCELLED]);
    }

    public function markAsProcessing(?string $externalId = null): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'external_id' => $externalId,
            'processed_at' => now(),
        ]);
    }

    public function markAsPaid(): void
    {
        $this->update([
            'status' => self::STATUS_PAID,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(?string $failureCode = null, ?string $failureReason = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'failure_code' => $failureCode,
            'failure_reason' => $failureReason,
            'completed_at' => now(),
        ]);
    }
}
