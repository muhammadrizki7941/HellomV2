<?php

namespace App\Http\Controllers\Api\V1\Hellom\Billing;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Http\Controllers\Api\V1\Hellom\Billing\Concerns\ActivatesPlans;
use App\Http\Controllers\Api\V1\Hellom\Billing\Concerns\PresentsWallets;
use App\Http\Controllers\Api\V1\Hellom\InvoiceController;
use App\Models\AppCatalog;
use App\Models\CheckoutIntent;
use App\Models\Entitlement;
use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CheckoutNotifier;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mock payment endpoints for local testing. Routed behind the `billing.mock` middleware
 * (BILLING_MOCK_ENABLED); never enabled in production.
 */
class BillingMockController extends BaseApiController
{
    use ActivatesPlans, PresentsWallets;

    public function __construct(
        private readonly CheckoutNotifier $checkoutNotifier,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function checkoutIntentMock(Request $request): JsonResponse
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
            'app_slug' => ['required', 'string'],
            'plan_slug' => ['required', 'string'],
        ]);

        $app = AppCatalog::query()
            ->where('slug', (string) $validated['app_slug'])
            ->where('is_active', true)
            ->first();

        if (!$app) {
            return $this->fail('App not found', ['code' => 'APP_NOT_FOUND'], 404);
        }

        $plan = Plan::query()
            ->where('slug', (string) $validated['plan_slug'])
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            return $this->fail('Plan not found', ['code' => 'PLAN_NOT_FOUND'], 404);
        }

        if (!$this->planEligibleForApp((string) $plan->slug, (string) $app->slug)) {
            return $this->fail('Plan is not eligible for selected app', ['code' => 'PLAN_NOT_ELIGIBLE'], 422);
        }

        $result = DB::transaction(function () use ($organizationId, $user, $app, $plan) {
            $subscription = Subscription::query()->create([
                'organization_id' => $organizationId,
                'app_id' => $app->id,
                'plan_id' => $plan->id,
                'status' => 'draft',
                'amount' => (int) $plan->price,
                'currency' => 'IDR',
                'billing_cycle' => 'monthly',
                'starts_at' => null,
                'ends_at' => null,
                'metadata' => [
                    'mode' => 'mock_checkout_intent',
                    'created_by_user_id' => $user->id,
                ],
            ]);

            $intentToken = Str::upper('mock_'.Str::random(24));
            $intent = CheckoutIntent::query()->create([
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'app_id' => $app->id,
                'plan_id' => $plan->id,
                'subscription_id' => $subscription->id,
                'intent_token' => $intentToken,
                'status' => 'pending',
                'amount' => (int) $plan->price,
                'currency' => 'IDR',
                'metadata' => [
                    'mode' => 'mock',
                    'app_slug' => $app->slug,
                    'plan_slug' => $plan->slug,
                ],
            ]);

            $currentEntitlement = Entitlement::query()
                ->with('plan')
                ->where('organization_id', $organizationId)
                ->where('app_id', $app->id)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();

            return [
                'intent' => $intent,
                'subscription' => $subscription,
                'current_entitlement' => $currentEntitlement,
            ];
        });

        /** @var CheckoutIntent $intent */
        $intent = $result['intent'];
        /** @var Subscription $subscription */
        $subscription = $result['subscription'];
        /** @var Entitlement|null $currentEntitlement */
        $currentEntitlement = $result['current_entitlement'];

        return $this->ok([
            'checkout_intent' => [
                'id' => $intent->id,
                'intent_token' => $intent->intent_token,
                'status' => $intent->status,
                'amount' => (int) $intent->amount,
                'currency' => $intent->currency,
            ],
            'subscription_draft' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'app_slug' => (string) $app->slug,
                'plan_slug' => (string) $plan->slug,
                'amount' => (int) $subscription->amount,
                'currency' => $subscription->currency,
            ],
            'current_entitlement' => [
                'status' => (string) ($currentEntitlement?->status ?? 'locked'),
                'plan_slug' => (string) ($currentEntitlement?->plan?->slug ?? ''),
            ],
            'next_step' => [
                'action' => 'mock_payment_confirm',
                'endpoint' => '/api/v1/hellom/billing/checkout-confirm-mock',
                'payload' => [
                    'intent_token' => $intent->intent_token,
                ],
            ],
        ], 'Checkout intent created');
    }

    public function checkoutConfirmMock(Request $request): JsonResponse
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

        DB::transaction(function () use ($intent) {
            $now = now();

            $intent->forceFill([
                'status' => 'confirmed',
            ])->save();

            if ($intent->subscription) {
                $intent->subscription->forceFill([
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => $this->entitlements()->subscriptionEndsAt($intent->subscription, $now, $intent->plan),
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
            if ((int) $intent->amount > 0 && $intent->subscription) {
                InvoiceController::generateFromCheckout(
                    organizationId: (int) $intent->organization_id,
                    subscriptionId: (int) $intent->subscription->id,
                    amount: (int) $intent->amount,
                    discount: 0,
                    appSlug: (string) ($intent->app?->slug ?? ''),
                    planSlug: (string) ($intent->plan?->slug ?? ''),
                    paymentMethod: 'mock',
                );
            }
        });

        $freshIntent = $intent->fresh(['organization', 'user', 'app', 'plan', 'subscription']);
        if ($freshIntent instanceof CheckoutIntent) {
            $this->notificationService->createGatewayPaymentSuccessNotif($freshIntent, 'Mock');
            if ($freshIntent->user instanceof User && $freshIntent->subscription instanceof Subscription) {
                $productName = (string) ($freshIntent->app?->name ?? 'Aplikasi');
                $this->notificationService->notifyConsumerPaymentSuccess($freshIntent->user, $freshIntent, $productName);
                $this->notificationService->notifyConsumerAccessActivated($freshIntent->user, $freshIntent->subscription, $productName);
            }
        }

        $this->checkoutNotifier->sendSubscriptionBillingNotification((int) ($intent->subscription?->id ?? 0), 'Aktivasi langganan berhasil');

        return $this->ok([
            'intent_token' => $intent->intent_token,
            'intent_status' => 'confirmed',
            'subscription_status' => (string) ($intent->subscription?->fresh()?->status ?? 'active'),
            'app_slug' => (string) ($intent->app?->slug ?? ''),
            'plan_slug' => (string) ($intent->plan?->slug ?? ''),
        ], 'Mock checkout confirmed');
    }

    public function renewSubscriptionMock(Request $request, int $subscriptionId): JsonResponse
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

        DB::transaction(function () use ($subscription, $organizationId) {
            $now = now();
            $subscription->forceFill([
                'status' => 'active',
                'starts_at' => $now,
                'ends_at' => $this->entitlements()->subscriptionEndsAt($subscription, $now),
            ])->save();

            $this->entitlements()->grantForSubscription($subscription, $now);

            $this->ensurePosProvisioning((string) ($subscription->app?->slug ?? ''), $organizationId);
        });

        $subscription = $subscription->fresh(['app', 'plan']);
        $entitlement = Entitlement::query()
            ->where('organization_id', $organizationId)
            ->where('app_id', $subscription->app_id)
            ->first();

        return $this->ok([
            'subscription' => [
                'id' => $subscription->id,
                'status' => (string) $subscription->status,
                'app_slug' => (string) ($subscription->app?->slug ?? ''),
                'plan_slug' => (string) ($subscription->plan?->slug ?? ''),
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
            ],
            'entitlement' => [
                'status' => (string) ($entitlement?->status ?? ''),
            ],
        ], 'Subscription renewed/reactivated (mock)');
    }

    public function walletTopupMock(Request $request): JsonResponse
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
            'amount' => ['required', 'integer', 'min:10000'],
            'source' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $result = DB::transaction(function () use ($organizationId, $user, $validated) {
            $wallet = OrganizationWallet::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (!$wallet instanceof OrganizationWallet) {
                $wallet = OrganizationWallet::query()->create([
                    'organization_id' => $organizationId,
                    'currency' => 'IDR',
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'total_in' => 0,
                    'total_out' => 0,
                    'status' => 'active',
                ]);
            }

            $amount = (int) $validated['amount'];
            $externalRef = 'topup_' . Str::upper(Str::random(16));

            $wallet->forceFill([
                'available_balance' => (int) $wallet->available_balance + $amount,
                'total_in' => (int) $wallet->total_in + $amount,
            ])->save();

            $transaction = OrganizationWalletTransaction::query()->create([
                'organization_id' => $organizationId,
                'wallet_id' => (int) $wallet->id,
                'user_id' => (int) $user->id,
                'type' => 'wallet_topup_mock',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => (int) $wallet->available_balance,
                'reference_type' => 'billing_wallet_topup',
                'reference_id' => $externalRef,
                'external_ref' => $externalRef,
                'description' => 'Wallet top-up (mock)',
                'metadata' => [
                    'source' => isset($validated['source']) ? (string) $validated['source'] : 'manual',
                    'notes' => isset($validated['notes']) ? (string) $validated['notes'] : null,
                ],
            ]);

            return [
                'wallet' => $wallet->fresh(),
                'transaction' => $transaction,
            ];
        });

        return $this->ok([
            'wallet' => $this->walletPayload($result['wallet']),
            'topup' => $this->walletTransactionPayload($result['transaction']),
        ], 'Wallet top-up success (mock)', 201);
    }
}
