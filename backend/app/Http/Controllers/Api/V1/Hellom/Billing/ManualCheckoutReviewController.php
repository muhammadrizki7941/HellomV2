<?php

namespace App\Http\Controllers\Api\V1\Hellom\Billing;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Http\Controllers\Api\V1\Hellom\Billing\Concerns\ActivatesPlans;
use App\Http\Controllers\Api\V1\Hellom\InvoiceController;
use App\Models\CheckoutIntent;
use App\Services\Billing\CheckoutNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin review of manual (bank transfer / QRIS) checkouts: list, approve, reject.
 */
class ManualCheckoutReviewController extends BaseApiController
{
    use ActivatesPlans;

    public function __construct(
        private readonly CheckoutNotifier $checkoutNotifier,
    ) {
    }

    public function adminPendingCheckouts(Request $request): JsonResponse
    {
        $limit = max(1, min((int) ($request->query('limit') ?: 50), 100));

        $items = CheckoutIntent::query()
            ->with([
                'user:id,name,email',
                'app:id,name,slug',
                'plan:id,name,slug,type',
                'subscription:id,organization_id,status',
                'organization:id,name,slug',
            ])
            ->whereIn('status', ['manual_review', 'awaiting_manual_review'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->ok([
            'items' => $items->map(function (CheckoutIntent $intent): array {
                return [
                    'id' => (int) $intent->id,
                    'intent_token' => (string) $intent->intent_token,
                    'status' => (string) $intent->status,
                    'amount' => (int) $intent->amount,
                    'currency' => (string) $intent->currency,
                    'created_at' => $intent->created_at,
                    'organization' => [
                        'id' => (int) ($intent->organization?->id ?? 0),
                        'name' => (string) ($intent->organization?->name ?? ''),
                    ],
                    'user' => [
                        'id' => (int) ($intent->user?->id ?? 0),
                        'name' => (string) ($intent->user?->name ?? ''),
                        'email' => (string) ($intent->user?->email ?? ''),
                    ],
                    'app' => [
                        'slug' => (string) ($intent->app?->slug ?? ''),
                        'name' => (string) ($intent->app?->name ?? ''),
                    ],
                    'plan' => [
                        'slug' => (string) ($intent->plan?->slug ?? ''),
                        'name' => (string) ($intent->plan?->name ?? ''),
                    ],
                    'manual_payment_method' => (string) data_get($intent->metadata, 'manual_payment_method', ''),
                ];
            })->values(),
        ], 'Pending manual checkouts');
    }

    public function adminApproveManualCheckout(Request $request, int $intentId): JsonResponse
    {
        $intent = CheckoutIntent::query()
            ->with(['subscription', 'app', 'plan'])
            ->find($intentId);

        if (!$intent) {
            return $this->fail('Checkout intent not found', ['code' => 'INTENT_NOT_FOUND'], 404);
        }

        if (!in_array((string) $intent->status, ['manual_review', 'awaiting_manual_review'], true)) {
            return $this->fail('Checkout intent is not awaiting manual review', ['code' => 'INTENT_NOT_REVIEWABLE'], 422);
        }

        DB::transaction(function () use ($intent): void {
            $now = now();

            $intent->forceFill([
                'status' => 'confirmed',
            ])->save();

            if ($intent->subscription) {
                $subMeta = is_array($intent->subscription->metadata) ? $intent->subscription->metadata : [];
                $subMeta['activation_source'] = 'manual_confirmation';
                $subMeta['manual_confirmed_at'] = $now->toISOString();

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

            if ((int) $intent->amount > 0) {
                \App\Models\PlatformFinanceLedger::recordRevenue(
                    'manual_subscription_payment',
                    (int) $intent->amount,
                    (int) $intent->organization_id,
                    'checkout_intents',
                    (int) $intent->id,
                    'Manual subscription checkout approved by admin'
                );
            }

            if ($intent->subscription && (int) $intent->amount > 0) {
                InvoiceController::generateFromCheckout(
                    organizationId: (int) $intent->organization_id,
                    subscriptionId: (int) $intent->subscription->id,
                    amount: (int) $intent->amount,
                    discount: 0,
                    appSlug: (string) ($intent->app?->slug ?? ''),
                    planSlug: (string) ($intent->plan?->slug ?? ''),
                    paymentMethod: 'manual_confirmation',
                );
            }

            $this->ensurePosProvisioning((string) ($intent->app?->slug ?? ''), (int) $intent->organization_id);
        });

        $freshIntent = $intent->fresh(['subscription.organization.users', 'app', 'plan', 'user']);
        if ($freshIntent instanceof CheckoutIntent) {
            $this->checkoutNotifier->sendCheckoutDecisionNotifications($freshIntent, true);
        }

        return $this->ok([
            'intent_id' => (int) $intent->id,
            'status' => 'confirmed',
        ], 'Manual checkout approved');
    }

    public function adminRejectManualCheckout(Request $request, int $intentId): JsonResponse
    {
        $intent = CheckoutIntent::query()
            ->with('subscription')
            ->find($intentId);

        if (!$intent) {
            return $this->fail('Checkout intent not found', ['code' => 'INTENT_NOT_FOUND'], 404);
        }

        if (!in_array((string) $intent->status, ['manual_review', 'awaiting_manual_review'], true)) {
            return $this->fail('Checkout intent is not awaiting manual review', ['code' => 'INTENT_NOT_REVIEWABLE'], 422);
        }

        DB::transaction(function () use ($intent): void {
            $intent->forceFill([
                'status' => 'rejected',
            ])->save();

            if ($intent->subscription) {
                $intent->subscription->forceFill([
                    'status' => 'cancelled',
                ])->save();
            }
        });

        $freshIntent = $intent->fresh(['subscription.organization.users', 'app', 'plan', 'user']);
        if ($freshIntent instanceof CheckoutIntent) {
            $this->checkoutNotifier->sendCheckoutDecisionNotifications($freshIntent, false);
        }

        return $this->ok([
            'intent_id' => (int) $intent->id,
            'status' => 'rejected',
        ], 'Manual checkout rejected');
    }
}
