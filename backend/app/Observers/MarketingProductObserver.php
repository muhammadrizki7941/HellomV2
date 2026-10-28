<?php

namespace App\Observers;

use App\Models\ProductPurchase;
use App\Services\PlatformMarketing;

class MarketingProductObserver
{
    public function creating(ProductPurchase $purchase): void
    {
        $context = app(PlatformMarketing::class)->context();
        if ($context) $purchase->marketing_context = $context;
    }

    public function saved(ProductPurchase $purchase): void
    {
        $context = $purchase->marketing_context ?? [];
        if (!$context || (int) $purchase->amount_paid <= 0) return;
        if ($purchase->payment_status === 'paid') $name = 'Purchase';
        elseif ($purchase->wasRecentlyCreated) $name = 'InitiateCheckout';
        else return;
        app(PlatformMarketing::class)->record($name, strtolower($name) . '_product_' . $purchase->id, $context, (int) $purchase->amount_paid, (string) $purchase->product?->slug, $purchase->user);
    }
}
