<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One business event in the double-entry finance journal (lines sum to zero). */
class FinanceJournalEntry extends Model
{
    public const SOURCE_LANDING = 'landing_page';
    public const SOURCE_DIGITAL_PRODUCT = 'digital_product';
    public const SOURCE_SUBSCRIPTION = 'subscription';
    public const SOURCE_WALLET_TOPUP = 'wallet_topup';
    public const SOURCE_SELLER_FINANCE = 'seller_finance';

    protected $fillable = [
        'event_key', 'event_type', 'source', 'source_type', 'source_id', 'provider', 'organization_id',
        'amount', 'occurred_at', 'description', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinanceJournalLine::class, 'entry_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
