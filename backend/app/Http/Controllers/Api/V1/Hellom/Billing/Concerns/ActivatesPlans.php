<?php

namespace App\Http\Controllers\Api\V1\Hellom\Billing\Concerns;

use App\Models\Plan;
use App\Services\Billing\EntitlementService;
use App\Services\Hellom\PosProvisioningService;

/**
 * Plan/billing-cycle resolution and entitlement activation helpers shared by the
 * billing controllers.
 */
trait ActivatesPlans
{
    private function ensurePosProvisioning(string $appSlug, int $organizationId): void
    {
        if ($appSlug !== 'pos') {
            return;
        }

        app(PosProvisioningService::class)->ensureProvisionedForPos($organizationId);
    }

    private function planEligibleForApp(string $planSlug, string $appSlug): bool
    {
        return match ($appSlug) {
            'landing_builder' => $planSlug === 'free',
            'pos' => str_starts_with($planSlug, 'pos_'),
            default => false,
        };
    }

    private function resolveBillingCycle(Plan $plan, ?string $requestedBillingCycle): ?string
    {
        if ($plan->isFree()) {
            return 'lifetime';
        }

        if ($plan->isLifetime()) {
            return 'lifetime';
        }

        if ($requestedBillingCycle !== null) {
            if ($requestedBillingCycle === 'yearly' && $plan->hasBillingCycle(Plan::BILLING_YEARLY)) {
                return 'yearly';
            }

            if ($requestedBillingCycle === 'monthly' && $plan->hasBillingCycle(Plan::BILLING_MONTHLY)) {
                return 'monthly';
            }
        }

        if ($plan->hasBillingCycle(Plan::BILLING_MONTHLY)) {
            return 'monthly';
        }

        if ($plan->hasBillingCycle(Plan::BILLING_YEARLY)) {
            return 'yearly';
        }

        if ($plan->type === Plan::TYPE_ONE_TIME && $plan->duration_days !== null && $plan->duration_days >= 365) {
            return 'yearly';
        }

        return $plan->type === Plan::TYPE_ONE_TIME ? 'lifetime' : null;
    }

    private function resolvePlanAmount(Plan $plan, string $billingCycle): int
    {
        if ($billingCycle === 'lifetime') {
            return (int) $plan->price;
        }

        return (int) $plan->getEffectivePrice($billingCycle);
    }

    private function entitlements(): EntitlementService
    {
        return app(EntitlementService::class);
    }
}
