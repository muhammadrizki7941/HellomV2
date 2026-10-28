<?php

namespace App\Observers;

use App\Models\CheckoutIntent;
use App\Services\PlatformMarketing;

class MarketingCheckoutObserver
{
    public function creating(CheckoutIntent $intent): void
    {
        $context = app(PlatformMarketing::class)->context();
        if ($context) $intent->metadata = [...($intent->metadata ?? []), 'marketing' => $context];
    }

    public function saved(CheckoutIntent $intent): void
    {
        $context = $intent->metadata['marketing'] ?? [];
        if (!$context) return;
        if (in_array($intent->status, ['confirmed', 'paid'], true) && $intent->amount > 0) {
            $name = 'Purchase';
        } elseif ($intent->wasRecentlyCreated) {
            $name = 'InitiateCheckout';
        } else return;
        app(PlatformMarketing::class)->record($name, strtolower($name) . '_' . $intent->intent_token, $context, $intent->amount, (string) $intent->app?->slug, $intent->user);
    }
}
