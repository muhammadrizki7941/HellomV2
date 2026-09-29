<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Subscription;
use App\Services\Billing\EntitlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cancels subscriptions that should not grant access (owner decision: subscriptions paid
 * with mock top-up money are revoked) and ends the app access they gave, through
 * EntitlementService. Report only by default; --force writes. Nothing is deleted.
 *
 *   php artisan billing:revoke-subscription 13 16 --reason="Dibayar dengan saldo uji coba"
 *   php artisan billing:revoke-subscription --mock-paid     (lists wallet-paid subscriptions of orgs that had mock top-ups)
 */
class RevokeSubscriptionCommand extends Command
{
    protected $signature = 'billing:revoke-subscription {ids?* : subscription ids} {--mock-paid : list candidates paid from wallets that had mock top-ups}
        {--reason=Dibayar dengan saldo uji coba (top-up mock) : stored on the subscription} {--force : revoke}';

    protected $description = 'Report (or revoke with --force) subscriptions and the app access they granted';

    public function handle(EntitlementService $entitlements): int
    {
        $ids = array_map('intval', (array) $this->argument('ids'));
        if ($this->option('mock-paid')) {
            $this->listMockPaid();
            if ($ids === []) {
                return self::SUCCESS;
            }
        }
        if ($ids === []) {
            $this->error('Give subscription ids, or --mock-paid to list candidates.');

            return self::INVALID;
        }

        $rows = [];
        foreach (Subscription::query()->whereIn('id', $ids)->get() as $subscription) {
            $before = (string) $subscription->status;
            $action = $before === 'cancelled' ? 'already cancelled' : ($this->option('force') ? 'revoked' : 'would revoke');
            if ($this->option('force') && $before !== 'cancelled') {
                DB::transaction(function () use ($entitlements, $subscription): void {
                    $entitlements->revoke($subscription, now(), (string) $this->option('reason'));
                    AuditLog::record('billing.subscription_revoked', null, (int) $subscription->organization_id, 'subscription', $subscription->id,
                        null, ['reason' => (string) $this->option('reason')], ['source' => 'console']);
                });
            }
            $rows[] = [$subscription->id, $subscription->organization_id, $subscription->app_id, $subscription->plan_id, $before, (int) $subscription->amount, $action];
        }
        $this->table(['subscription', 'organization', 'app', 'plan', 'status before', 'amount', 'action'], $rows);
        if (!$this->option('force')) {
            $this->comment('Report only. Run with --force to revoke.');
        }

        return self::SUCCESS;
    }

    /** Subscriptions paid from the wallet (checkout_intents, payment_flow=wallet) in organizations that had mock top-ups. */
    private function listMockPaid(): void
    {
        $orgs = DB::table('organization_wallet_transactions')->where('type', 'wallet_topup_mock')->distinct()->pluck('organization_id');
        $rows = DB::table('checkout_intents')
            ->whereIn('organization_id', $orgs)
            ->where('status', 'confirmed')
            ->whereNotNull('subscription_id')
            ->where('metadata->payment_flow', 'wallet')
            ->orderBy('id')
            ->get(['id', 'organization_id', 'subscription_id', 'app_id', 'amount', 'created_at'])
            ->map(fn ($c) => [$c->organization_id, $c->id, $c->subscription_id, $c->app_id, (int) $c->amount, $c->created_at, Subscription::query()->whereKey($c->subscription_id)->value('status')])
            ->all();
        $this->info('Wallet-paid subscriptions in organizations that had mock top-ups (check each: real money may also have been in the wallet):');
        $rows === [] ? $this->line('  none') : $this->table(['organization', 'checkout', 'subscription', 'app', 'amount', 'paid at', 'subscription status'], $rows);
    }
}
