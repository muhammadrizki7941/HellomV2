<?php

namespace App\Console\Commands;

use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * One-time backfill for entitlements created before access periods were
 * mirrored from subscriptions (they have ends_at = null even for paid,
 * time-bound plans). Report-only by default; writes only with --force.
 *
 *   php artisan hellom:billing:backfill-entitlement-ends            # report
 *   php artisan hellom:billing:backfill-entitlement-ends --force    # apply
 */
class BackfillEntitlementEndsCommand extends Command
{
    protected $signature = 'hellom:billing:backfill-entitlement-ends
        {--force : Actually write ends_at (default is a read-only report)}';

    protected $description = 'Copy the latest subscription ends_at onto paid entitlements that still have ends_at = null';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $now = now();
        $graceDays = (int) config('payments.billing.grace_days', 0);

        $entitlements = Entitlement::query()
            ->with('plan:id,slug,type')
            ->whereIn('status', ['active', 'trialing'])
            ->whereNull('ends_at')
            ->get();

        $rows = [];
        $counts = ['lifetime_or_free' => 0, 'no_subscription' => 0, 'still_valid' => 0, 'would_lock_now' => 0];

        foreach ($entitlements as $entitlement) {
            $plan = $entitlement->plan;
            if ($plan instanceof Plan && ($plan->isLifetime() || $plan->isFree())) {
                $counts['lifetime_or_free']++;
                continue;
            }

            $subscription = Subscription::query()
                ->where('organization_id', $entitlement->organization_id)
                ->where('app_id', $entitlement->app_id)
                ->whereIn('status', ['active', 'failed', 'expired'])
                ->orderByRaw('ends_at IS NULL DESC')
                ->orderByDesc('ends_at')
                ->first();

            if (!$subscription instanceof Subscription) {
                $counts['no_subscription']++;
                $rows[] = [$entitlement->organization_id, $plan?->slug ?? '-', '-', 'no subscription (left as is)'];
                continue;
            }

            if ($subscription->ends_at === null) {
                $counts['lifetime_or_free']++;
                continue;
            }

            $locks = $subscription->ends_at->copy()->addDays($graceDays)->isPast();
            $counts[$locks ? 'would_lock_now' : 'still_valid']++;
            $rows[] = [
                $entitlement->organization_id,
                $plan?->slug ?? '-',
                $subscription->ends_at->toDateTimeString(),
                $locks ? 'LOCKS NOW' : 'ok',
            ];

            if ($force) {
                $entitlement->forceFill(['ends_at' => $subscription->ends_at])->save();
            }
        }

        if ($rows) {
            $this->table(['organization_id', 'plan', 'subscription ends_at', 'effect'], $rows);
        }

        foreach ($counts as $key => $value) {
            $this->line("- {$key}: {$value}");
        }
        $this->info($force ? 'Backfill applied.' : 'Report only. Re-run with --force to apply.');

        return self::SUCCESS;
    }
}
