<?php

namespace App\Services\Billing;

use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonInterface;

/**
 * Single place that grants or ends paid app access.
 *
 * Entitlements mirror the subscription that paid for them: ends_at is the
 * subscription's ends_at (null only for lifetime/free plans), so access
 * stops on time through EnsureAppEntitlement even if the scheduler is down.
 */
class EntitlementService
{
    /**
     * Access period for a plan bought at $start. A missing plan keeps the
     * historical one-month default.
     */
    public function periodEndsAt(?Plan $plan, CarbonInterface $start, ?string $billingCycle = null): ?CarbonInterface
    {
        if (!$plan instanceof Plan) {
            return $start->copy()->addMonth();
        }

        return $plan->accessEndsAt($start, $billingCycle);
    }

    /** Access period for (re)activating $subscription at $start. */
    public function subscriptionEndsAt(Subscription $subscription, CarbonInterface $start, ?Plan $fallbackPlan = null): ?CarbonInterface
    {
        $plan = $subscription->plan instanceof Plan ? $subscription->plan : $fallbackPlan;

        return $this->periodEndsAt($plan, $start, $subscription->billing_cycle ? (string) $subscription->billing_cycle : null);
    }

    /** Grant active access for an organization/app with an explicit period. */
    public function grant(int $organizationId, int $appId, ?int $planId, CarbonInterface $startsAt, ?CarbonInterface $endsAt): Entitlement
    {
        return Entitlement::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'app_id' => $appId,
            ],
            [
                'plan_id' => $planId,
                'status' => 'active',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]
        );
    }

    /** Grant access that lasts exactly as long as $subscription. */
    public function grantForSubscription(Subscription $subscription, CarbonInterface $startsAt): Entitlement
    {
        return $this->grant(
            (int) $subscription->organization_id,
            (int) $subscription->app_id,
            $subscription->plan_id ? (int) $subscription->plan_id : null,
            $startsAt,
            $subscription->ends_at
        );
    }
}
