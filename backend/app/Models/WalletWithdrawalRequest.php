<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletWithdrawalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'wallet_id',
        'requested_by_user_id',
        'reviewed_by_user_id',
        'status',
        'amount',
        'fee_amount',
        'net_amount',
        'bank_code',
        'account_number',
        'account_name',
        'provider',
        'external_ref',
        'provider_ref',
        'processed_at',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'wallet_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'reviewed_by_user_id' => 'integer',
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'net_amount' => 'integer',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(OrganizationWallet::class, 'wallet_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
