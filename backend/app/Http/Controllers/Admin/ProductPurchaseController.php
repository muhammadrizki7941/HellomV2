<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Models\ProductPurchase;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductPurchaseController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductPurchase::query()->with(['user', 'product']);

        $userId = $request->query('user_id');
        if ($userId) {
            $query->where('user_id', $userId);
        }

        $status = $request->query('status');
        if ($status) {
            $query->where('payment_status', $status);
        }

        $paymentGateway = $request->query('payment_gateway');
        if ($paymentGateway) {
            $query->where('payment_gateway', $paymentGateway);
        }

        $productId = $request->query('product_id');
        if ($productId) {
            $query->where('product_id', $productId);
        }

        $startDate = $request->query('start_date');
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        $endDate = $request->query('end_date');
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $perPage = (int) $request->query('per_page', 20);
        $items = $query->orderByDesc('created_at')->paginate($perPage);

        return $this->ok([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ], 'Product purchases loaded');
    }

    public function show(string $id): JsonResponse
    {
        $purchase = ProductPurchase::query()->with(['user', 'product'])->findOrFail($id);

        return $this->ok($purchase, 'Purchase detail');
    }

    public function approve(string $id, NotificationService $notificationService): JsonResponse
    {
        $purchase = ProductPurchase::query()->with(['user', 'product'])->findOrFail($id);
        if ($purchase->payment_status === 'paid') {
            return $this->ok($purchase, 'Purchase already approved');
        }

        if ($purchase->payment_gateway !== 'manual') {
            return $this->fail('Hanya pembayaran manual yang bisa dikonfirmasi manual oleh super admin.', ['code' => 'ONLY_MANUAL_PURCHASE_CAN_BE_APPROVED'], 422);
        }

        DB::transaction(function () use ($purchase) {
            $purchase->forceFill([
                'payment_status' => 'paid',
                'paid_at' => $purchase->paid_at ?? now(),
            ])->save();

            $purchase->product?->increment('total_purchases');

            \App\Models\OwnerNotification::query()
                ->where('reference_type', 'digital_product_purchase')
                ->where('reference_id', $purchase->id)
                ->where('action_status', 'pending')
                ->update([
                    'action_status' => 'done',
                    'action_done_at' => now(),
                ]);
        });

        if ($purchase->user && $purchase->product) {
            $notificationService->notifyConsumerPaymentSuccess($purchase->user, $purchase, $purchase->product->name);
            $notificationService->notifyConsumerAccessActivated($purchase->user, null, $purchase->product->name);
        }

        return $this->ok($purchase->fresh(), 'Purchase approved');
    }

    public function refund(string $id, NotificationService $notificationService): JsonResponse
    {
        $purchase = ProductPurchase::query()->with(['user', 'product'])->findOrFail($id);
        $purchase->forceFill([
            'payment_status' => 'refunded',
        ])->save();

        if ($purchase->user) {
            $notificationService->notifyConsumerRefundProcessed($purchase->user, $purchase);
        }

        return $this->ok($purchase->fresh(), 'Purchase refunded');
    }
}
