<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationPayoutProfile extends Model
{
    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'organization_id',
        'submitted_by_user_id',
        'reviewed_by_user_id',
        'full_name',
        'nik',
        'ktp_image_disk',
        'ktp_image_path',
        'bank_code',
        'bank_name',
        'account_number',
        'account_name',
        'status',
        'review_notes',
        'submitted_at',
        'reviewed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isVerified(): bool
    {
        return (string) $this->status === self::STATUS_VERIFIED;
    }
}
