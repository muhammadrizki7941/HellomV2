<?php

namespace App\Models;

use App\Observers\FinanceJournalObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(FinanceJournalObserver::class)]
class OrganizationWalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'wallet_id',
        'user_id',
        'type',
        'direction',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'external_ref',
        'description',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'wallet_id' => 'integer',
            'user_id' => 'integer',
            'amount' => 'integer',
            'balance_after' => 'integer',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
