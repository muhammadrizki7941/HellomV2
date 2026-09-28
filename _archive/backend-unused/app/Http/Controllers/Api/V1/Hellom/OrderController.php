<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Order;
use App\Models\PosLoyaltySetting;
use App\Models\PosMember;
use App\Models\PosPointTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * POS order payment confirmation (POST /pos/orders/{orderId}/payment).
 * The rest of the POS CRUD lives in the Pos\* controllers.
 */
class OrderController extends Controller
{
    // ─── Helper: ambil tenant slug dari org ───
    private function getTenantSlug(Organization $org): string
    {
        return (string) ($org->pos_tenant_slug ?? $org->slug);
    }

    // ─── Helper: ambil org dari user ───
    private function getOrg(Request $request): ?Organization
    {
        return $request->user()?->currentOrganization;
    }

    public function confirmPayment(
        Request $request,
        int $orderId
    ): JsonResponse {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            $org = $this->getOrg($request);
            $tenantSlug = $org ? $this->getTenantSlug($org) : null;
        }

        if (!$tenantSlug) {
            return response()->json([
                'success' => false,
                'message' => 'Konteks POS tidak tersedia',
            ], 403);
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:cash,transfer,qris,other',
            'payment_amount' => 'required|integer|min:0',
            'payment_note'   => 'nullable|string|max:200',
        ]);

        /** @var Order $order */
        $order = DB::transaction(function () use ($tenantSlug, $orderId, $validated): Order {
            $order = Order::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantSlug)
                ->lockForUpdate()
                ->findOrFail($orderId);

            if ($order->payment_status === 'paid') {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'This order has already been paid',
                ], 422));
            }

            $finalAmount = (int) ($order->final_amount ?? $order->total_amount);

            if ($validated['payment_method'] === 'cash'
                && $validated['payment_amount'] < $finalAmount) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Jumlah bayar kurang dari total pesanan',
                    'data' => [
                        'total'   => $finalAmount,
                        'paid'    => $validated['payment_amount'],
                        'kurang'  => $finalAmount - $validated['payment_amount'],
                    ],
                ], 422));
            }

            $change = max(0, $validated['payment_amount'] - $finalAmount);

            $order->update([
                'payment_method' => $validated['payment_method'],
                'payment_amount' => $validated['payment_amount'],
                'payment_change' => $change,
                'payment_note'   => $validated['payment_note'] ?? null,
                'payment_status' => 'paid',
                'paid_at'        => now(),
                'status'         => 'completed',
            ]);

            return $order->fresh();
        });

        $finalAmount = (int) ($order->final_amount ?? $order->total_amount);
        $change = max(0, (int) $order->payment_amount - $finalAmount);

        // Award poin loyalitas jika ada member (setelah paid)
        if ($order->member_id) {
            try {
                $this->awardLoyaltyPoints($order, $tenantSlug);
            } catch (\Exception $e) {
                // Log error but don't fail the payment
                \Log::error('Failed to award loyalty points', [
                    'order_id' => $order->id,
                    'member_id' => $order->member_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order' => [
                    'id'             => $order->id,
                    'order_number'   => $order->order_number,
                    'total_amount'   => $finalAmount,
                    'payment_method' => $order->payment_method,
                    'payment_amount' => $order->payment_amount,
                    'payment_change' => $order->payment_change,
                    'payment_status' => 'paid',
                    'status'         => 'completed',
                    'paid_at'        => $order->paid_at,
                ],
                'change_amount' => $change,
            ],
            'message' => 'Payment confirmed successfully! ✅',
        ]);
    }

    private function awardLoyaltyPoints(Order $order, string $tenantSlug): void
    {
        $member = PosMember::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
            ->find($order->member_id);

        if (!$member) {
            return;
        }

        $settings = PosLoyaltySetting::currentForTenant($tenantSlug);
        if (!$settings || !$settings->enabled) {
            return;
        }

        $spendAmount = $order->final_amount ?? $order->total_amount ?? 0;
        if ($spendAmount < (int) $settings->min_spend_amount) {
            return;
        }

        $pointsToEarn = $this->calculatePointsToEarn($settings, $spendAmount);

        if ($pointsToEarn <= 0) {
            return;
        }

        // Record transaction
        PosPointTransaction::create([
            'tenant_id' => $tenantSlug,
            'member_id' => $member->id,
            'order_id' => $order->id,
            'type' => 'earn',
            'points' => $pointsToEarn,
            'balance_after' => $member->total_points + $pointsToEarn,
            'description' => "Poin dari pesanan {$order->order_number}",
        ]);

        // Update member
        $member->increment('total_points', $pointsToEarn);
        $member->increment('redeemable_points', $pointsToEarn);
        $member->increment('total_orders');
        $member->increment('total_spent', $spendAmount);
        $member->update(['last_order_at' => now()]);

        // Update order
        $order->update(['points_earned' => $pointsToEarn]);
    }

    private function calculatePointsToEarn(PosLoyaltySetting $settings, int $amount): int
    {
        if (!$settings->enabled) {
            return 0;
        }

        if ($amount < (int) $settings->min_spend_amount) {
            return 0;
        }

        $points = (int) floor($amount / max(1, (int) $settings->points_per_amount));

        if ($settings->max_points_per_order !== null) {
            $points = min($points, (int) $settings->max_points_per_order);
        }

        return max(0, $points);
    }
}
