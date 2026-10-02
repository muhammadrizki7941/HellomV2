<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Models\AuditLog;
use App\Models\OwnerNotification;
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

        $perPage = max(1, min((int) $request->query('per_page', 20), 100));
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

    public function approve(Request $request, string $id, NotificationService $notificationService): JsonResponse
    {
        $purchase = ProductPurchase::query()->with(['user', 'product'])->findOrFail($id);
        if ($purchase->payment_gateway !== 'manual') {
            return $this->fail('Hanya pembayaran manual yang bisa dikonfirmasi manual oleh super admin.', ['code' => 'ONLY_MANUAL_PURCHASE_CAN_BE_APPROVED'], 422);
        }

        // Locked: a double click or two admins confirm (and count) the purchase once.
        $outcome = DB::transaction(function () use ($purchase): string {
            $locked = ProductPurchase::query()->lockForUpdate()->find((int) $purchase->id);
            if (!$locked instanceof ProductPurchase || $locked->payment_status === 'paid') {
                return 'already_paid';
            }
            if ($locked->payment_status === 'refunded') {
                return 'refunded';
            }

            $locked->forceFill([
                'payment_status' => 'paid',
                'paid_at' => $locked->paid_at ?? now(),
            ])->save();
            $locked->product?->increment('total_purchases');

            OwnerNotification::query()
                ->where('reference_type', 'digital_product_purchase')
                ->where('reference_id', $locked->id)
                ->where('action_status', 'pending')
                ->update([
                    'action_status' => 'done',
                    'action_done_at' => now(),
                ]);

            return 'approved';
        }, 3);

        if ($outcome === 'refunded') {
            return $this->fail('Pembelian ini sudah direfund dan tidak bisa dikonfirmasi lagi.', ['code' => 'PURCHASE_REFUNDED'], 422);
        }
        if ($outcome === 'already_paid') {
            return $this->ok($purchase->fresh(), 'Pembelian ini sudah dikonfirmasi');
        }

        AuditLog::record('digital_product.purchase_approved', $request->user()?->id, null, 'product_purchase', (int) $purchase->id,
            null, ['amount_paid' => (int) $purchase->amount_paid], null, $request->ip());

        if ($purchase->user && $purchase->product) {
            $notificationService->notifyConsumerPaymentSuccess($purchase->user, $purchase, $purchase->product->name);
            $notificationService->notifyConsumerAccessActivated($purchase->user, null, $purchase->product->name);
        }

        return $this->ok($purchase->fresh(), 'Pembelian dikonfirmasi');
    }

    /**
     * Marks a paid purchase as refunded (access ends). The money itself is returned
     * outside Hellom (gateway dashboard / bank transfer), so only paid purchases qualify.
     */
    public function refund(Request $request, string $id, NotificationService $notificationService): JsonResponse
    {
        $purchase = ProductPurchase::query()->with(['user', 'product'])->findOrFail($id);

        $refundedNow = DB::transaction(function () use ($purchase): bool {
            $locked = ProductPurchase::query()->lockForUpdate()->find((int) $purchase->id);
            if (!$locked instanceof ProductPurchase || $locked->payment_status !== 'paid') {
                return false;
            }
            $locked->forceFill(['payment_status' => 'refunded'])->save();

            return true;
        }, 3);

        if (!$refundedNow) {
            return $this->fail('Hanya pembelian yang sudah lunas yang bisa direfund.', ['code' => 'PURCHASE_NOT_REFUNDABLE'], 422);
        }

        AuditLog::record('digital_product.purchase_refunded', $request->user()?->id, null, 'product_purchase', (int) $purchase->id,
            ['payment_status' => 'paid'], ['payment_status' => 'refunded'], null, $request->ip());

        if ($purchase->user) {
            $notificationService->notifyConsumerRefundProcessed($purchase->user, $purchase);
        }

        return $this->ok($purchase->fresh(), 'Pembelian direfund');
    }
}
