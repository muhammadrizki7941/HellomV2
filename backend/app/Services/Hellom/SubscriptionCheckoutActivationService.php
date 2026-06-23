<?php

namespace App\Services\Hellom;

use App\Http\Controllers\Api\V1\Hellom\InvoiceController;
use App\Models\CheckoutIntent;
use App\Models\Entitlement;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlatformFinanceLedger;
use App\Models\Subscription;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionCheckoutActivationService
{
    public function __construct(
        private readonly PosProvisioningService $posProvisioningService,
        private readonly NotificationService $notificationService,
    ) {
    }

    /**
     * Confirm a gateway (iPaymu/Xendit/DOKU) checkout once payment is verified.
     * Used by both the provider webhook and the manual reconcile endpoint, so
     * activation works even when the inbound webhook can't reach the server.
     *
     * @param array<string,mixed> $gatewayMeta transaction_id, session_id, invoice_id, ...
     * @return bool true if this call newly activated the checkout
     */
    public function confirmGatewayCheckout(CheckoutIntent $intent, array $gatewayMeta, string $providerLabel): bool
    {
        $intent->loadMissing(['subscription.plan', 'app', 'plan', 'user']);
        $providerKey = strtolower(preg_replace('/[^a-z0-9]+/i', '', $providerLabel) ?: 'gateway');

        if (in_array((string) $intent->status, ['confirmed', 'paid'], true)) {
            $this->ensureActiveAccessForConfirmedCheckout($intent);

            return false;
        }

        $meta = array_filter($gatewayMeta, fn ($value) => $value !== null && $value !== '' && $value !== 0);

        DB::transaction(function () use ($intent, $meta, $providerKey, $providerLabel): void {
            $now = now();

            $intentMeta = is_array($intent->metadata) ? $intent->metadata : [];
            $intentMeta[$providerKey] = array_merge($intentMeta[$providerKey] ?? [], $meta);
            $intentMeta['activated_at'] = $now->toISOString();
            $intent->forceFill(['status' => 'confirmed', 'metadata' => $intentMeta])->save();

            $subscription = $intent->subscription;
            if ($subscription instanceof Subscription) {
                $subMeta = is_array($subscription->metadata) ? $subscription->metadata : [];
                $subMeta['activation_source'] = $providerKey . '_payment';
                $subMeta[$providerKey] = array_merge($subMeta[$providerKey] ?? [], $meta);

                $subscription->forceFill([
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => $this->resolveSubscriptionEndAt($subscription->plan ?? $intent->plan, $now),
                    'metadata' => $subMeta,
                ])->save();
            }

            $this->upsertActiveEntitlement($intent, $now);

            $invoiceId = (int) ($meta['invoice_id'] ?? 0);
            if ($invoiceId > 0) {
                $invoice = Invoice::query()->find($invoiceId);
                if ($invoice instanceof Invoice) {
                    $invoiceMeta = is_array($invoice->metadata) ? $invoice->metadata : [];
                    $invoiceMeta[$providerKey] = array_merge($invoiceMeta[$providerKey] ?? [], $meta);
                    $invoice->forceFill(['status' => 'paid', 'paid_at' => $now, 'metadata' => $invoiceMeta])->save();
                }
            }

            if ((int) $intent->amount > 0) {
                PlatformFinanceLedger::recordRevenue(
                    $providerKey . '_subscription_payment',
                    (int) $intent->amount,
                    (int) $intent->organization_id,
                    'checkout_intents',
                    (int) $intent->id,
                    'Subscription payment confirmed via ' . $providerLabel
                );
            }

            $this->ensurePosProvisioning($intent);
        });

        // In-app notifications: owner success + consumer activation.
        $this->notificationService->createGatewayPaymentSuccessNotif($intent, $providerLabel);
        if ($intent->user instanceof User) {
            $productName = (string) ($intent->app?->name ?? 'Aplikasi');
            $this->notificationService->notifyConsumerPaymentSuccess($intent->user, $intent, $productName);
            if ($intent->subscription instanceof Subscription) {
                $this->notificationService->notifyConsumerAccessActivated($intent->user, $intent->subscription, $productName);
            }
        }

        return true;
    }

    public function approveManualCheckout(CheckoutIntent $intent): CheckoutIntent
    {
        return DB::transaction(function () use ($intent): CheckoutIntent {
            $lockedIntent = CheckoutIntent::query()
                ->with(['subscription.plan', 'app', 'plan'])
                ->lockForUpdate()
                ->findOrFail((int) $intent->id);

            if (in_array((string) $lockedIntent->status, ['confirmed', 'paid'], true)) {
                $this->ensureActiveAccessForConfirmedCheckout($lockedIntent);

                return $lockedIntent->fresh(['subscription', 'app', 'plan']) ?? $lockedIntent;
            }

            if (!in_array((string) $lockedIntent->status, ['manual_review', 'awaiting_manual_review'], true)) {
                throw new \DomainException('Checkout intent is not awaiting manual review.');
            }

            $now = now();
            $intentMeta = is_array($lockedIntent->metadata) ? $lockedIntent->metadata : [];
            $intentMeta['manual_confirmed_at'] = $now->toISOString();

            $lockedIntent->forceFill([
                'status' => 'confirmed',
                'metadata' => $intentMeta,
            ])->save();

            $subscription = $lockedIntent->subscription;
            if ($subscription instanceof Subscription) {
                $subMeta = is_array($subscription->metadata) ? $subscription->metadata : [];
                $subMeta['activation_source'] = 'manual_confirmation';
                $subMeta['manual_confirmed_at'] = $now->toISOString();

                $subscription->forceFill([
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => $this->resolveSubscriptionEndAt($subscription->plan ?? $lockedIntent->plan, $now),
                    'metadata' => $subMeta,
                ])->save();
            }

            $this->upsertActiveEntitlement($lockedIntent, $now);

            if ((int) $lockedIntent->amount > 0) {
                PlatformFinanceLedger::recordRevenue(
                    'manual_subscription_payment',
                    (int) $lockedIntent->amount,
                    (int) $lockedIntent->organization_id,
                    'checkout_intents',
                    (int) $lockedIntent->id,
                    'Manual subscription checkout approved'
                );
            }

            if ($subscription instanceof Subscription && (int) $lockedIntent->amount > 0) {
                InvoiceController::generateFromCheckout(
                    organizationId: (int) $lockedIntent->organization_id,
                    subscriptionId: (int) $subscription->id,
                    amount: (int) $lockedIntent->amount,
                    discount: 0,
                    appSlug: (string) ($lockedIntent->app?->slug ?? ''),
                    planSlug: (string) ($lockedIntent->plan?->slug ?? ''),
                    paymentMethod: 'manual_confirmation',
                );
            }

            $this->ensurePosProvisioning($lockedIntent);

            return $lockedIntent->fresh(['subscription', 'app', 'plan']) ?? $lockedIntent;
        });
    }

    public function ensureActiveAccessForConfirmedCheckout(CheckoutIntent $intent): void
    {
        if (!in_array((string) $intent->status, ['confirmed', 'paid'], true)) {
            return;
        }

        $now = now();
        $subscription = $intent->subscription;
        if ($subscription instanceof Subscription && (string) $subscription->status !== 'active') {
            $subscription->forceFill([
                'status' => 'active',
                'starts_at' => $subscription->starts_at ?? $now,
                'ends_at' => $subscription->ends_at ?? $this->resolveSubscriptionEndAt($subscription->plan ?? $intent->plan, $now),
            ])->save();
        }

        $this->upsertActiveEntitlement($intent, $now);
        $this->ensurePosProvisioning($intent);
    }

    private function upsertActiveEntitlement(CheckoutIntent $intent, Carbon $now): void
    {
        if ((int) $intent->organization_id <= 0 || (int) $intent->app_id <= 0) {
            return;
        }

        Entitlement::query()->updateOrCreate(
            [
                'organization_id' => (int) $intent->organization_id,
                'app_id' => (int) $intent->app_id,
            ],
            [
                'plan_id' => (int) $intent->plan_id,
                'status' => 'active',
                'starts_at' => $now,
                'ends_at' => null,
            ]
        );
    }

    private function ensurePosProvisioning(CheckoutIntent $intent): void
    {
        if ((string) ($intent->app?->slug ?? '') !== 'pos' || (int) $intent->organization_id <= 0) {
            return;
        }

        $this->posProvisioningService->ensureProvisionedForPos((int) $intent->organization_id);
    }

    private function resolveSubscriptionEndAt(?Plan $plan, Carbon $startAt): ?Carbon
    {
        if (!$plan instanceof Plan) {
            return $startAt->copy()->addMonth();
        }

        if ($plan->isLifetime() || $plan->isFree()) {
            return null;
        }

        if ($plan->duration_days) {
            return $startAt->copy()->addDays((int) $plan->duration_days);
        }

        if ($plan->hasBillingCycle(Plan::BILLING_YEARLY)) {
            return $startAt->copy()->addYear();
        }

        return $startAt->copy()->addMonth();
    }
}
