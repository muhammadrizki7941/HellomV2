<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Models\CheckoutIntent;
use App\Models\OwnerNotification;
use App\Services\Hellom\SubscriptionCheckoutActivationService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerNotificationController extends BaseApiController
{
    public function __construct(
        private readonly SubscriptionCheckoutActivationService $checkoutActivation,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = OwnerNotification::query()
            ->orderBy('is_read', 'asc') // unread first
            ->orderBy('created_at', 'desc');

        $type = $request->query('type');
        if ($type) {
            $query->where('type', $type);
        }

        $perPage = (int) $request->query('per_page', 20);
        $notifications = $query->paginate($perPage);

        return $this->ok([
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ],
        ], 'Notifications loaded');
    }

    public function unreadCount(): JsonResponse
    {
        $count = OwnerNotification::where('is_read', false)->count();

        return $this->ok(['count' => $count], 'Unread count loaded');
    }

    public function markAsRead(string $id): JsonResponse
    {
        $notification = OwnerNotification::findOrFail($id);
        $notification->update(['is_read' => true]);

        return $this->ok(['message' => 'Notification marked as read'], 'Notification marked as read');
    }

    public function markAllAsRead(): JsonResponse
    {
        OwnerNotification::where('is_read', false)->update(['is_read' => true]);

        return $this->ok(['message' => 'All notifications marked as read'], 'All notifications marked as read');
    }

    public function show(string $id): JsonResponse
    {
        $notification = OwnerNotification::query()->findOrFail($id);

        return $this->ok($notification, 'Notification loaded');
    }

    public function executeAction(string $id): JsonResponse
    {
        $notification = OwnerNotification::query()->findOrFail($id);

        $isCheckoutAction = in_array((string) $notification->action_type, ['verify_payment', 'open_access'], true)
            && (string) $notification->reference_type === 'checkout_intent'
            && (int) $notification->reference_id > 0;

        if ($isCheckoutAction) {
            $intent = CheckoutIntent::query()
                ->with(['subscription.organization.users', 'subscription.plan', 'app', 'plan', 'user'])
                ->find((int) $notification->reference_id);

            if (!$intent instanceof CheckoutIntent) {
                return $this->fail('Checkout intent not found', ['code' => 'INTENT_NOT_FOUND'], 404);
            }

            try {
                $approvedNow = false;
                if (in_array((string) $intent->status, ['manual_review', 'awaiting_manual_review'], true)) {
                    $intent = $this->checkoutActivation->approveManualCheckout($intent);
                    $approvedNow = true;
                } elseif (in_array((string) $intent->status, ['confirmed', 'paid'], true)) {
                    $this->checkoutActivation->ensureActiveAccessForConfirmedCheckout($intent);
                } else {
                    return $this->fail('Checkout intent is not ready to open access', ['code' => 'INTENT_NOT_ACCESSIBLE'], 422);
                }

                if ($approvedNow && $intent->user) {
                    $productName = (string) ($intent->app?->name ?? 'Aplikasi');
                    $this->notificationService->notifyConsumerPaymentSuccess($intent->user, $intent, $productName);
                    $this->notificationService->notifyConsumerAccessActivated($intent->user, $intent->subscription, $productName);
                }
            } catch (\DomainException) {
                return $this->fail('Checkout intent is not awaiting manual review', ['code' => 'INTENT_NOT_REVIEWABLE'], 422);
            }
        }

        $notification->forceFill([
            'action_status' => 'done',
            'action_done_at' => now(),
            'is_read' => true,
        ])->save();

        return $this->ok(true, 'Aksi berhasil diproses');
    }

    public function ignoreAction(string $id): JsonResponse
    {
        $notification = OwnerNotification::query()->findOrFail($id);
        $notification->forceFill([
            'action_status' => 'ignored',
        ])->save();

        return $this->ok(true, 'Notification action ignored');
    }

    public function destroy(string $id): JsonResponse
    {
        $notification = OwnerNotification::findOrFail($id);
        $notification->delete();

        return $this->ok(['message' => 'Notification deleted'], 'Notification deleted');
    }
}
