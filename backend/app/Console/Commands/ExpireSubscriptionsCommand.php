<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Billing\EntitlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marks ended subscriptions as expired when nothing else will renew them:
 * yearly/prepaid plans (no auto-renew) and monthly plans whose wallet
 * auto-renew is disabled. Monthly auto-renew is handled by
 * hellom:billing:auto-renew-wallet. Access itself already stops at
 * entitlements.ends_at; this keeps stored statuses consistent.
 */
class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'hellom:billing:expire-subscriptions
        {--limit=500 : Maximum subscriptions to process}
        {--dry-run : Show what would expire without saving}';

    protected $description = 'Expire ended subscriptions that are not auto-renewed (yearly/prepaid, auto-renew off)';

    public function __construct(private readonly EntitlementService $entitlements)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = now();
        $graceDays = (int) config('payments.billing.grace_days', 0);
        $cutoff = $now->copy()->subDays($graceDays);
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Subscription::query()
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $cutoff)
            ->orderBy('ends_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $expired = 0;
        $skippedAutoRenew = 0;

        foreach ($candidates as $subscription) {
            $autoRenew = (bool) data_get($subscription->metadata, 'wallet_auto_renew', true);
            if ((string) $subscription->billing_cycle === 'monthly' && $autoRenew) {
                $skippedAutoRenew++;
                continue;
            }

            $reason = (string) $subscription->billing_cycle === 'monthly' ? 'auto_renew_disabled' : 'period_ended';

            if ($dryRun) {
                $this->line("would expire subscription #{$subscription->id} (org {$subscription->organization_id}, {$subscription->billing_cycle}, ended {$subscription->ends_at}) [{$reason}]");
                $expired++;
                continue;
            }

            DB::transaction(fn () => $this->entitlements->expire($subscription, $now, $reason));
            $expired++;
        }

        $mode = $dryRun ? 'DRY RUN' : 'EXECUTED';
        $this->info("Expire subscriptions {$mode}: expired={$expired}, left_for_auto_renew={$skippedAutoRenew}, grace_days={$graceDays}");

        return self::SUCCESS;
    }
}
