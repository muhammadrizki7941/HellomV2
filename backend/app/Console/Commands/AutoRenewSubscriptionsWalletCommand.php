<?php

namespace App\Console\Commands;

use App\Models\Entitlement;
use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;
use App\Models\Subscription;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoRenewSubscriptionsWalletCommand extends Command
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected $signature = 'hellom:billing:auto-renew-wallet
        {--organization-id= : Process only one organization id}
        {--limit=500 : Max due subscriptions to process}
        {--dry-run : Simulate without DB writes}';

    protected $description = 'Auto-renew due subscriptions by charging organization wallet balance';

    public function handle(): int
    {
        $now = now();
        $organizationId = (int) ($this->option('organization-id') ?? 0);
        $limit = (int) ($this->option('limit') ?? 500);
        $dryRun = (bool) $this->option('dry-run');

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 5000) {
            $limit = 5000;
        }

        $query = Subscription::query()
            ->with(['app:id,slug', 'plan:id,slug', 'organization:id,name'])
            ->where('billing_cycle', 'monthly')
            ->whereIn('status', ['active', 'failed'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->orderBy('ends_at')
            ->orderBy('id')
            ->limit($limit);

        if ($organizationId > 0) {
            $query->where('organization_id', $organizationId);
        }

        $dueSubscriptions = $query->get();

        $summary = [
            'scanned' => (int) $dueSubscriptions->count(),
            'renewed' => 0,
            'failed_insufficient_balance' => 0,
            'failed_invalid_amount' => 0,
            'skipped_auto_disabled' => 0,
            'skipped_not_due' => 0,
        ];

        foreach ($dueSubscriptions as $subscription) {
            $autoRenewEnabled = (bool) data_get($subscription->metadata, 'wallet_auto_renew', true);
            if (!$autoRenewEnabled) {
                $summary['skipped_auto_disabled']++;
                continue;
            }

            if ($dryRun) {
                $wallet = OrganizationWallet::query()
                    ->where('organization_id', (int) $subscription->organization_id)
                    ->first();

                $amount = (int) $subscription->amount;
                if ($amount < 0) {
                    $summary['failed_invalid_amount']++;
                } elseif ($amount > 0 && ((int) ($wallet?->available_balance ?? 0)) < $amount) {
                    $summary['failed_insufficient_balance']++;
                } else {
                    $summary['renewed']++;
                }

                continue;
            }

            $result = $this->processSubscriptionRenewal((int) $subscription->id, $now);
            if (array_key_exists($result, $summary)) {
                $summary[$result]++;
            }
        }

        $mode = $dryRun ? 'DRY RUN' : 'EXECUTED';
        $this->info("Auto-renew wallet {$mode} summary:");
        foreach ($summary as $key => $value) {
            $this->line("- {$key}: {$value}");
        }

        return 0;
    }

    private function processSubscriptionRenewal(int $subscriptionId, $now): string
    {
        return DB::transaction(function () use ($subscriptionId, $now): string {
            $subscription = Subscription::query()
                ->with(['app:id,slug', 'plan:id,slug', 'organization:id,name'])
                ->where('id', $subscriptionId)
                ->lockForUpdate()
                ->first();

            if (!$subscription instanceof Subscription) {
                return 'skipped_not_due';
            }

            if ($subscription->ends_at === null || $subscription->ends_at->gt($now)) {
                return 'skipped_not_due';
            }

            $autoRenewEnabled = (bool) data_get($subscription->metadata, 'wallet_auto_renew', true);
            if (!$autoRenewEnabled) {
                return 'skipped_auto_disabled';
            }

            $wallet = OrganizationWallet::query()
                ->where('organization_id', (int) $subscription->organization_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet instanceof OrganizationWallet) {
                $wallet = OrganizationWallet::query()->create([
                    'organization_id' => (int) $subscription->organization_id,
                    'currency' => 'IDR',
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'total_in' => 0,
                    'total_out' => 0,
                    'status' => 'active',
                ]);
            }

            $amount = (int) $subscription->amount;
            if ($amount < 0) {
                $this->markRenewalFailed($subscription, 'invalid_amount', $now, null);
                return 'failed_invalid_amount';
            }

            if ($amount > 0 && (int) $wallet->available_balance < $amount) {
                $this->markRenewalFailed($subscription, 'insufficient_balance', $now, [
                    'available_balance' => (int) $wallet->available_balance,
                    'required_amount' => $amount,
                ]);
                return 'failed_insufficient_balance';
            }

            $externalRef = 'autorenew_' . strtoupper((string) \Illuminate\Support\Str::random(14));

            if ($amount > 0) {
                $wallet->forceFill([
                    'available_balance' => (int) $wallet->available_balance - $amount,
                    'total_out' => (int) $wallet->total_out + $amount,
                ])->save();

                OrganizationWalletTransaction::query()->create([
                    'organization_id' => (int) $subscription->organization_id,
                    'wallet_id' => (int) $wallet->id,
                    'user_id' => null,
                    'type' => 'subscription_auto_renew_debit',
                    'direction' => 'debit',
                    'amount' => $amount,
                    'balance_after' => (int) $wallet->available_balance,
                    'reference_type' => 'subscriptions',
                    'reference_id' => (string) $subscription->id,
                    'external_ref' => $externalRef,
                    'description' => 'Auto-renew subscription charged from wallet',
                    'metadata' => [
                        'subscription_id' => (int) $subscription->id,
                        'app_slug' => (string) ($subscription->app?->slug ?? ''),
                        'plan_slug' => (string) ($subscription->plan?->slug ?? ''),
                        'mode' => 'scheduler_auto_renew_wallet',
                    ],
                ]);
            }

            $renewalStart = $subscription->ends_at->copy();
            if ($renewalStart->lt($now)) {
                $renewalStart = $now->copy();
            }
            $renewalEnd = $renewalStart->copy()->addMonth();

            $meta = is_array($subscription->metadata) ? $subscription->metadata : [];
            $meta['wallet_last_auto_renew'] = [
                'status' => 'success',
                'charged_amount' => $amount,
                'charged_at' => $now->toISOString(),
                'external_ref' => $externalRef,
            ];

            $subscription->forceFill([
                'status' => 'active',
                'starts_at' => $renewalStart,
                'ends_at' => $renewalEnd,
                'metadata' => $meta,
            ])->save();

            Entitlement::query()->updateOrCreate(
                [
                    'organization_id' => (int) $subscription->organization_id,
                    'app_id' => (int) $subscription->app_id,
                ],
                [
                    'plan_id' => (int) $subscription->plan_id,
                    'status' => 'active',
                    'starts_at' => $renewalStart,
                    'ends_at' => null,
                ]
            );

            // Create notification for successful auto-renewal
            $this->notificationService->createSubscriptionRenewalNotif($subscription, $amount, 'auto_wallet');

            return 'renewed';
        });
    }

    private function markRenewalFailed(Subscription $subscription, string $reason, $now, ?array $extra): void
    {
        $meta = is_array($subscription->metadata) ? $subscription->metadata : [];
        $meta['wallet_last_auto_renew'] = array_filter([
            'status' => 'failed',
            'reason' => $reason,
            'attempted_at' => $now->toISOString(),
            'extra' => $extra,
        ], static fn($value) => $value !== null);

        $subscription->forceFill([
            'status' => 'failed',
            'metadata' => $meta,
        ])->save();

        $appSlug = (string) ($subscription->app?->slug ?? '');
        $entitlementStatus = $appSlug === 'landing_builder' ? 'active' : 'expired';

        Entitlement::query()->updateOrCreate(
            [
                'organization_id' => (int) $subscription->organization_id,
                'app_id' => (int) $subscription->app_id,
            ],
            [
                'plan_id' => (int) $subscription->plan_id,
                'status' => $entitlementStatus,
                'starts_at' => null,
                'ends_at' => $now,
            ]
        );
    }
}
