<?php

namespace App\Services\Hellom;

use App\Http\Controllers\Api\V1\Hellom\InvoiceController;
use App\Models\CheckoutIntent;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\PlatformFinanceLedger;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionCheckoutActivationService
{
    public function __construct(
        private readonly PosProvisioningService $posProvisioningService,
    ) {
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
