<?php

namespace App\Observers;

use App\Models\ProductPurchase;
use App\Services\DigitalProducts\ProductAccessMailer;
use App\Services\Finance\JournalRecorder;

/**
 * Every "paid" transition (iPaymu/Xendit/DOKU webhooks, iPaymu status sync, manual
 * approval by the super admin) passes through here, so guest buyers always get
 * their access email, and paid/refunded purchases reach the finance journal. Runs after
 * the surrounding transaction commits.
 */
class ProductPurchaseObserver
{
    public bool $afterCommit = true;

    public function saved(ProductPurchase $purchase): void
    {
        if (!$purchase->wasChanged('payment_status') && !$purchase->wasRecentlyCreated) {
            return;
        }

        if (in_array($purchase->payment_status, ['paid', 'refunded'], true)) {
            JournalRecorder::safely(fn (JournalRecorder $journal) => $journal->productPurchase($purchase));
        }

        if ($purchase->payment_status !== 'paid' || !$purchase->isGuestCheckout()) {
            return;
        }

        try {
            app(ProductAccessMailer::class)->sendForPaidPurchase($purchase);
        } catch (\Throwable $exception) {
            // Never break the webhook/approval that marked the purchase paid.
            report($exception);
        }
    }
}
