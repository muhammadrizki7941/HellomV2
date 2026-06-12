<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'currency',
        'available_balance',
        'pending_balance',
        'total_in',
        'total_out',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'available_balance' => 'integer',
            'pending_balance' => 'integer',
            'total_in' => 'integer',
            'total_out' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OrganizationWalletTransaction::class, 'wallet_id');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(WalletWithdrawalRequest::class, 'wallet_id');
    }
}
