<?php

namespace App\Observers;

use App\Models\CheckoutIntent;
use App\Models\OrganizationWalletTransaction;
use App\Services\Finance\JournalRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * Mirrors subscription checkouts and wallet top-ups/debits into the finance journal
 * after the surrounding transaction commits. Idempotent; query-builder updates that
 * skip model events are caught by the scheduled finance:journal-backfill.
 */
class FinanceJournalObserver
{
    public bool $afterCommit = true;

    public function saved(Model $model): void
    {
        if ($model instanceof CheckoutIntent && $model->status === 'confirmed' && ($model->wasChanged('status') || $model->wasRecentlyCreated)) {
            JournalRecorder::safely(fn (JournalRecorder $journal) => $journal->checkoutIntent($model));
        }
        if ($model instanceof OrganizationWalletTransaction && $model->wasRecentlyCreated) {
            JournalRecorder::safely(fn (JournalRecorder $journal) => $journal->walletTransaction($model));
        }
    }
}
