<?php

namespace App\Observers;

use App\Models\ProductPurchase;


/**
 * Every "paid" transition (iPaymu/Xendit/DOKU webhooks, iPaymu status sync, manual
 * approval by the super admin) passes through here, so buyers get
 * their access email, with queue retries. Runs after the surrounding transaction commits.
 */
class ProductPurchaseObserver
{
    public bool $afterCommit = true;

    public function saved(ProductPurchase $purchase): void
    {
        if (!$purchase->wasChanged('payment_status') && !$purchase->wasRecentlyCreated) {
            return;
        }

        if ($purchase->payment_status !== 'paid') {
            return;
        }

        try {
            \App\Jobs\SendProductReceipt::dispatch((int) $purchase->id)->afterCommit();
        } catch (\Throwable $exception) {
            // Never break the webhook/approval that marked the purchase paid.
            report($exception);
        }
    }
}
