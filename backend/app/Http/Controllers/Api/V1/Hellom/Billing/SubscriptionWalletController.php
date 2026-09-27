<?php

namespace App\Http\Controllers\Api\V1\Hellom\Billing;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Http\Controllers\Api\V1\Hellom\Billing\Concerns\ActivatesPlans;
use App\Http\Controllers\Api\V1\Hellom\Billing\Concerns\PresentsWallets;
use App\Http\Controllers\Api\V1\Hellom\InvoiceController;
use App\Models\CheckoutIntent;
use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CheckoutNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paying for subscriptions from the organization wallet: checkout, manual renew,
 * auto-renew toggle and auto-renew preview.
 */
class SubscriptionWalletController extends BaseApiController
{
    use ActivatesPlans, PresentsWallets;

    public function __construct(
        private readonly CheckoutNotifier $checkoutNotifier,
    ) {
    }

    public function checkoutConfirmWallet(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'intent_token' => ['required', 'string'],
        ]);

        $intent = CheckoutIntent::query()
            ->with(['subscription', 'plan', 'app'])
            ->where('intent_token', (string) $validated['intent_token'])
            ->where('organization_id', $organizationId)
            ->first();

        if (!$intent) {
            return $this->fail('Checkout intent not found', ['code' => 'INTENT_NOT_FOUND'], 404);
        }

        if ((string) $intent->status === 'confirmed') {
            return $this->ok([
                'intent_token' => $intent->intent_token,
                'status' => 'already_confirmed',
            ], 'Checkout already confirmed');
        }

        if ((string) $intent->status !== 'pending') {
            return $this->fail('Only pending checkout can be paid by wallet', ['code' => 'INTENT_NOT_PENDING'], 422);
        }

        $result = DB::transaction(function () use ($intent, $organizationId, $user) {
            $wallet = OrganizationWallet::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (!$wallet instanceof OrganizationWallet) {
                return ['error' => $this->fail('Wallet not found', ['code' => 'WALLET_NOT_FOUND'], 404)];
            }

            $amount = (int) $intent->amount;
            if ($amount < 0) {
                return ['error' => $this->fail('Invalid checkout amount', ['code' => 'INVALID_CHECKOUT_AMOUNT'], 422)];
            }

            if ($amount > 0 && (int) $wallet->available_balance < $amount) {
                return ['error' => $this->fail('Insufficient wallet balance for checkout', [
                    'code' => 'INSUFFICIENT_WALLET_BALANCE',
                    'available_balance' => (int) $wallet->available_balance,
                    'required_amount' => $amount,
                ], 422)];
            }

            if ($amount > 0) {
                $wallet->forceFill([
                    'available_balance' => (int) $wallet->available_balance - $amount,
                    'total_out' => (int) $wallet->total_out + $amount,
                ])->save();
            }

            $externalRef = 'chk_wallet_' . Str::upper(Str::random(14));

            if ($amount > 0) {
                OrganizationWalletTransaction::query()->create([
                    'organization_id' => $organizationId,
                    'wallet_id' => (int) $wallet->id,
                    'user_id' => (int) $user->id,
                    'type' => 'app_checkout_debit',
                    'direction' => 'debit',
                    'amount' => $amount,
                    'balance_after' => (int) $wallet->available_balance,
                    'reference_type' => 'checkout_intents',
                    'reference_id' => (string) $intent->id,
                    'external_ref' => $externalRef,
                    'description' => 'Checkout paid using wallet balance',
                    'metadata' => [
                        'intent_token' => (string) $intent->intent_token,
                        'app_slug' => (string) ($intent->app?->slug ?? ''),
                        'plan_slug' => (string) ($intent->plan?->slug ?? ''),
                    ],
                ]);
            }

            $intentMeta = is_array($intent->metadata) ? $intent->metadata : [];
            $intentMeta['wallet_payment'] = [
                'charged_at' => now()->toISOString(),
                'charged_amount' => $amount,
                'charged_by_user_id' => (int) $user->id,
                'external_ref' => $externalRef,
            ];

            $now = now();
            $intent->forceFill([
                'status' => 'confirmed',
                'metadata' => $intentMeta,
            ])->save();

            if ($intent->subscription) {
                $subMeta = is_array($intent->subscription->metadata) ? $intent->subscription->metadata : [];
                $subMeta['activation_source'] = 'wallet';
                $subMeta['activation_external_ref'] = $externalRef;

                $intent->subscription->forceFill([
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => $this->entitlements()->subscriptionEndsAt($intent->subscription, $now, $intent->plan),
                    'metadata' => $subMeta,
                ])->save();
            }

            $this->entitlements()->grant(
                (int) $intent->organization_id,
                (int) $intent->app_id,
                (int) $intent->plan_id,
                $now,
                $intent->subscription
                    ? $intent->subscription->ends_at
                    : $this->entitlements()->periodEndsAt($intent->plan, $now)
            );

            $this->ensurePosProvisioning((string) ($intent->app?->slug ?? ''), (int) $intent->organization_id);

            // Generate invoice
            if ($amount > 0) {
                \App\Models\PlatformFinanceLedger::recordRevenue(
                    'wallet_subscription_payment',
                    $amount,
                    (int) $intent->organization_id,
                    'checkout_intents',
                    (int) $intent->id,
                    'Subscription checkout paid using organization wallet'
                );
            }

            if ($amount > 0 && $intent->subscription) {
                InvoiceController::generateFromCheckout(
                    organizationId: (int) $intent->organization_id,
                    subscriptionId: (int) $intent->subscription->id,
                    amount: $amount,
                    discount: 0,
                    appSlug: (string) ($intent->app?->slug ?? ''),
                    planSlug: (string) ($intent->plan?->slug ?? ''),
                    paymentMethod: 'wallet',
                );
            }

            return [
                'wallet' => $wallet->fresh(),
                'intent' => $intent->fresh(['subscription', 'app', 'plan']),
            ];
        });

        if (isset($result['error']) && $result['error'] instanceof JsonResponse) {
            return $result['error'];
        }

        /** @var CheckoutIntent $confirmedIntent */
        $confirmedIntent = $result['intent'];
        $this->checkoutNotifier->sendSubscriptionBillingNotification((int) ($confirmedIntent->subscription?->id ?? 0), 'Pembayaran wallet berhasil');

        return $this->ok([
            'wallet' => $this->walletPayload($result['wallet']),
            'intent_token' => (string) $confirmedIntent->intent_token,
            'intent_status' => (string) $confirmedIntent->status,
            'subscription_status' => (string) ($confirmedIntent->subscription?->status ?? 'active'),
            'app_slug' => (string) ($confirmedIntent->app?->slug ?? ''),
            'plan_slug' => (string) ($confirmedIntent->plan?->slug ?? ''),
        ], 'Checkout confirmed using wallet');
    }

    public function renewSubscriptionWallet(Request $request, int $subscriptionId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $subscription = Subscription::query()
            ->with(['app', 'plan'])
            ->where('id', $subscriptionId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$subscription) {
            return $this->fail('Subscription not found', ['code' => 'SUBSCRIPTION_NOT_FOUND'], 404);
        }

        $result = DB::transaction(function () use ($subscription, $organizationId, $user) {
            $wallet = OrganizationWallet::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (!$wallet instanceof OrganizationWallet) {
                return ['error' => $this->fail('Wallet not found', ['code' => 'WALLET_NOT_FOUND'], 404)];
            }

            $chargeAmount = (int) $subscription->amount;
            if ($chargeAmount < 0) {
                return ['error' => $this->fail('Invalid subscription amount', ['code' => 'INVALID_SUBSCRIPTION_AMOUNT'], 422)];
            }

            if ($chargeAmount > 0 && (int) $wallet->available_balance < $chargeAmount) {
                return ['error' => $this->fail('Insufficient wallet balance for renewal', [
                    'code' => 'INSUFFICIENT_WALLET_BALANCE',
                    'available_balance' => (int) $wallet->available_balance,
                    'required_amount' => $chargeAmount,
                ], 422)];
            }

            if ($chargeAmount > 0) {
                $wallet->forceFill([
                    'available_balance' => (int) $wallet->available_balance - $chargeAmount,
                    'total_out' => (int) $wallet->total_out + $chargeAmount,
                ])->save();
            }

            $externalRef = 'renew_' . Str::upper(Str::random(16));

            if ($chargeAmount > 0) {
                OrganizationWalletTransaction::query()->create([
                    'organization_id' => $organizationId,
                    'wallet_id' => (int) $wallet->id,
                    'user_id' => (int) $user->id,
                    'type' => 'subscription_renew_debit',
                    'direction' => 'debit',
                    'amount' => $chargeAmount,
                    'balance_after' => (int) $wallet->available_balance,
                    'reference_type' => 'subscriptions',
                    'reference_id' => (string) $subscription->id,
                    'external_ref' => $externalRef,
                    'description' => 'Subscription renewal charged from wallet',
                    'metadata' => [
                        'subscription_id' => (int) $subscription->id,
                        'app_slug' => (string) ($subscription->app?->slug ?? ''),
                        'plan_slug' => (string) ($subscription->plan?->slug ?? ''),
                    ],
                ]);
            }

            $now = now();
            $meta = is_array($subscription->metadata) ? $subscription->metadata : [];
            $meta['wallet_last_charge'] = [
                'charged_at' => $now->toISOString(),
                'charged_amount' => $chargeAmount,
                'charged_by_user_id' => (int) $user->id,
                'external_ref' => $externalRef,
            ];

            $subscription->forceFill([
                'status' => 'active',
                'starts_at' => $now,
                'ends_at' => $this->entitlements()->subscriptionEndsAt($subscription, $now),
                'metadata' => $meta,
            ])->save();

            $this->entitlements()->grantForSubscription($subscription, $now);

            $this->ensurePosProvisioning((string) ($subscription->app?->slug ?? ''), $organizationId);

            return [
                'wallet' => $wallet->fresh(),
                'subscription' => $subscription->fresh(['app', 'plan']),
            ];
        });

        if (isset($result['error']) && $result['error'] instanceof JsonResponse) {
            return $result['error'];
        }

        /** @var Subscription $renewed */
        $renewed = $result['subscription'];

        return $this->ok([
            'wallet' => $this->walletPayload($result['wallet']),
            'subscription' => [
                'id' => $renewed->id,
                'status' => (string) $renewed->status,
                'app_slug' => (string) ($renewed->app?->slug ?? ''),
                'plan_slug' => (string) ($renewed->plan?->slug ?? ''),
                'starts_at' => $renewed->starts_at,
                'ends_at' => $renewed->ends_at,
            ],
        ], 'Subscription renewed using wallet');
    }

    public function setSubscriptionWalletAutoRenew(Request $request, int $subscriptionId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $subscription = Subscription::query()
            ->with(['app', 'plan'])
            ->where('id', $subscriptionId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$subscription) {
            return $this->fail('Subscription not found', ['code' => 'SUBSCRIPTION_NOT_FOUND'], 404);
        }

        $enabled = (bool) $validated['enabled'];
        $meta = is_array($subscription->metadata) ? $subscription->metadata : [];
        $meta['wallet_auto_renew'] = $enabled;
        $meta['wallet_auto_renew_updated_by_user_id'] = (int) $user->id;
        $meta['wallet_auto_renew_updated_at'] = now()->toISOString();

        $subscription->forceFill([
            'metadata' => $meta,
        ])->save();

        return $this->ok([
            'subscription' => [
                'id' => (int) $subscription->id,
                'status' => (string) $subscription->status,
                'app_slug' => (string) ($subscription->app?->slug ?? ''),
                'plan_slug' => (string) ($subscription->plan?->slug ?? ''),
                'wallet_auto_renew' => $enabled,
            ],
        ], 'Subscription wallet auto-renew updated');
    }

    public function walletAutoRenewPreview(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'include_overdue' => ['nullable', 'boolean'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $limit = (int) ($validated['limit'] ?? 100);
        $includeOverdue = (bool) ($validated['include_overdue'] ?? true);

        $now = now();
        $horizon = $now->copy()->addDays($days);

        $query = Subscription::query()
            ->with(['app:id,slug,name', 'plan:id,slug,name'])
            ->where('organization_id', $organizationId)
            ->where('billing_cycle', 'monthly')
            ->whereIn('status', ['active', 'failed'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $horizon)
            ->orderBy('ends_at')
            ->orderBy('id')
            ->limit($limit);

        if (!$includeOverdue) {
            $query->where('ends_at', '>', $now);
        }

        $subscriptions = $query->get();

        $wallet = OrganizationWallet::query()->where('organization_id', $organizationId)->first();
        $availableBalance = (int) ($wallet?->available_balance ?? 0);
        $runningBalance = $availableBalance;

        $totalDueAmount = 0;
        $payableAmount = 0;
        $unpayableAmount = 0;
        $overdueCount = 0;
        $upcomingCount = 0;

        $items = $subscriptions->map(function (Subscription $subscription) use (&$runningBalance, &$totalDueAmount, &$payableAmount, &$unpayableAmount, &$overdueCount, &$upcomingCount, $now): array {
            $amount = (int) $subscription->amount;
            $autoRenewEnabled = (bool) data_get($subscription->metadata, 'wallet_auto_renew', true);
            $isOverdue = $subscription->ends_at?->lte($now) ?? false;

            if ($isOverdue) {
                $overdueCount++;
            } else {
                $upcomingCount++;
            }

            $totalDueAmount += max(0, $amount);

            $canAutoCharge = false;
            $reason = null;
            $requiredTopup = 0;

            if (!$autoRenewEnabled) {
                $reason = 'auto_renew_disabled';
            } elseif ($amount < 0) {
                $reason = 'invalid_amount';
            } elseif ($amount === 0) {
                $canAutoCharge = true;
            } elseif ($runningBalance >= $amount) {
                $canAutoCharge = true;
                $runningBalance -= $amount;
            } else {
                $reason = 'insufficient_balance';
                $requiredTopup = $amount - $runningBalance;
            }

            if ($canAutoCharge) {
                $payableAmount += max(0, $amount);
            } else {
                $unpayableAmount += max(0, $amount);
            }

            return [
                'subscription_id' => (int) $subscription->id,
                'status' => (string) $subscription->status,
                'amount' => $amount,
                'currency' => (string) $subscription->currency,
                'billing_cycle' => (string) $subscription->billing_cycle,
                'ends_at' => $subscription->ends_at,
                'due_state' => $isOverdue ? 'overdue' : 'upcoming',
                'wallet_auto_renew' => $autoRenewEnabled,
                'can_auto_charge' => $canAutoCharge,
                'reason' => $reason,
                'required_topup' => $requiredTopup,
                'app' => [
                    'slug' => (string) ($subscription->app?->slug ?? ''),
                    'name' => (string) ($subscription->app?->name ?? ''),
                ],
                'plan' => [
                    'slug' => (string) ($subscription->plan?->slug ?? ''),
                    'name' => (string) ($subscription->plan?->name ?? ''),
                ],
            ];
        })->values();

        return $this->ok([
            'organization_id' => $organizationId,
            'filters' => [
                'days' => $days,
                'limit' => $limit,
                'include_overdue' => $includeOverdue,
                'horizon_at' => $horizon,
            ],
            'wallet' => $wallet ? $this->walletPayload($wallet) : [
                'id' => null,
                'organization_id' => $organizationId,
                'currency' => 'IDR',
                'available_balance' => 0,
                'pending_balance' => 0,
                'total_in' => 0,
                'total_out' => 0,
                'status' => 'active',
                'updated_at' => null,
            ],
            'summary' => [
                'total_due_count' => (int) $subscriptions->count(),
                'overdue_count' => $overdueCount,
                'upcoming_count' => $upcomingCount,
                'total_due_amount' => $totalDueAmount,
                'payable_amount' => $payableAmount,
                'unpayable_amount' => $unpayableAmount,
                'projected_remaining_balance' => $runningBalance,
                'minimum_topup_required' => max(0, $totalDueAmount - $availableBalance),
            ],
            'items' => $items,
        ], 'Wallet auto-renew preview');
    }
}
