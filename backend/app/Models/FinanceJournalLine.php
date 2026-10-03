<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One side of a journal entry: signed rupiah, debit positive, credit negative. */
class FinanceJournalLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['entry_id', 'account', 'account_type', 'organization_id', 'amount', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FinanceJournalEntry::class, 'entry_id');
    }
}
