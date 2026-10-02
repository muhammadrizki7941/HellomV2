<?php

namespace App\Http\Controllers\Api\V1\Hellom\Billing;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Models\AuditLog;
use App\Models\CheckoutIntent;
use App\Services\Billing\CheckoutNotifier;
use App\Services\Hellom\SubscriptionCheckoutActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin review of manual (bank transfer / QRIS) checkouts: list, approve, reject.
 */
class ManualCheckoutReviewController extends BaseApiController
{
    public function __construct(
        private readonly CheckoutNotifier $checkoutNotifier,
        private readonly SubscriptionCheckoutActivationService $activation,
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
        $intent = CheckoutIntent::query()->find($intentId);
        if (!$intent) {
            return $this->fail('Checkout tidak ditemukan', ['code' => 'INTENT_NOT_FOUND'], 404);
        }

        // Same locked service as the notification "execute" button: one approval, one
        // revenue entry and one invoice, however often it is clicked.
        try {
            $approvedNow = false;
            $intent = $this->activation->approveManualCheckout($intent, $approvedNow);
        } catch (\DomainException) {
            return $this->fail('Checkout ini tidak sedang menunggu konfirmasi manual', ['code' => 'INTENT_NOT_REVIEWABLE'], 422);
        }

        if (!$approvedNow) {
            return $this->ok(['intent_id' => (int) $intent->id, 'status' => (string) $intent->status], 'Checkout ini sudah disetujui sebelumnya');
        }

        AuditLog::record('billing.manual_checkout_approved', $request->user()?->id, (int) $intent->organization_id, 'checkout_intent', (int) $intent->id,
            null, ['amount' => (int) $intent->amount], null, $request->ip());

        $freshIntent = $intent->fresh(['subscription.organization.users', 'app', 'plan', 'user']);
        if ($freshIntent instanceof CheckoutIntent) {
            $this->checkoutNotifier->sendCheckoutDecisionNotifications($freshIntent, true);
        }

        return $this->ok([
            'intent_id' => (int) $intent->id,
            'status' => 'confirmed',
        ], 'Checkout manual disetujui');
    }

    public function adminRejectManualCheckout(Request $request, int $intentId): JsonResponse
    {
        $rejected = DB::transaction(function () use ($intentId): ?CheckoutIntent {
            $intent = CheckoutIntent::query()->with('subscription')->lockForUpdate()->find($intentId);
            if (!$intent || !in_array((string) $intent->status, ['manual_review', 'awaiting_manual_review'], true)) {
                return null;
            }

            $intent->forceFill(['status' => 'rejected'])->save();

            // Only the subscription this checkout created and that was never paid.
            if ($intent->subscription && in_array((string) $intent->subscription->status, ['pending_payment', 'draft'], true)) {
                $intent->subscription->forceFill(['status' => 'cancelled'])->save();
            }

            return $intent;
        }, 3);

        if (!$rejected instanceof CheckoutIntent) {
            return $this->fail('Checkout ini tidak sedang menunggu konfirmasi manual', ['code' => 'INTENT_NOT_REVIEWABLE'], 422);
        }

        AuditLog::record('billing.manual_checkout_rejected', $request->user()?->id, (int) $rejected->organization_id, 'checkout_intent', (int) $rejected->id,
            null, null, null, $request->ip());

        $freshIntent = $rejected->fresh(['subscription.organization.users', 'app', 'plan', 'user']);
        if ($freshIntent instanceof CheckoutIntent) {
            $this->checkoutNotifier->sendCheckoutDecisionNotifications($freshIntent, false);
        }

        return $this->ok([
            'intent_id' => (int) $rejected->id,
            'status' => 'rejected',
        ], 'Checkout manual ditolak');
    }
}
