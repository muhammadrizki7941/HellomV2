<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entitlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'app_id',
        'plan_id',
        'status',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Status used for access decisions: an active/trialing entitlement whose
     * ends_at (+ BILLING_GRACE_DAYS) has passed counts as "expired", even if
     * the scheduler has not updated the stored status yet.
     */
    public function effectiveStatus(): string
    {
        $status = (string) ($this->status ?? 'locked');

        if (in_array($status, ['active', 'trialing'], true) && $this->ends_at !== null) {
            $graceDays = (int) config('payments.billing.grace_days', 0);
            if ($this->ends_at->copy()->addDays($graceDays)->isPast()) {
                return 'expired';
            }
        }

        return $status;
    }

    public function allowsAccess(): bool
    {
        return in_array($this->effectiveStatus(), ['active', 'trialing'], true);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(AppCatalog::class, 'app_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
