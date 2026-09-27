<?php

namespace App\Observers;

use App\Models\ProductPurchase;
use App\Services\DigitalProducts\ProductAccessMailer;

/**
 * Every "paid" transition (iPaymu/Xendit/DOKU webhooks, iPaymu status sync, manual
 * approval by the super admin) passes through here, so guest buyers always get
 * their access email. Runs after the surrounding transaction commits.
 */
class ProductPurchaseObserver
{
    public bool $afterCommit = true;

    public function saved(ProductPurchase $purchase): void
    {
        if (!$purchase->wasChanged('payment_status') && !$purchase->wasRecentlyCreated) {
            return;
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
