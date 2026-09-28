<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cache of a seller's sales balance. The truth is seller_balance_ledger (pending and
 * available buckets) plus seller_withdrawals (processing, withdrawn); rebuild with
 * `php artisan balance:reconcile --fix`. Only App\Services\SellerFinance\SellerLedger writes it.
 */
class SellerBalance extends Model
{
    protected $primaryKey = 'organization_id';
    public $incrementing = false;

    protected $fillable = ['organization_id', 'pending', 'available', 'processing', 'withdrawn', 'hold_days_override', 'is_frozen', 'frozen_reason'];

    protected function casts(): array
    {
        return [
            'pending' => 'integer',
            'available' => 'integer',
            'processing' => 'integer',
            'withdrawn' => 'integer',
            'hold_days_override' => 'integer',
            'is_frozen' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
