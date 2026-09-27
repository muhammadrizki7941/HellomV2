<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Services\Loyalty\PointsService;
use App\Services\Realtime\RealtimeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()
            ->with('items.options')
            ->whereNotIn('status', [
                Order::STATUS_COMPLETED,
                Order::STATUS_CANCELLED,
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('admin.orders.index', [
            'orders' => $orders,
            'realtimePublicUrl' => (string) config('realtime.public_url'),
        ]);
    }

    public function history(Request $request)
    {
        $orders = Order::query()
            ->with('items.options')
            ->whereIn('status', [
                Order::STATUS_COMPLETED,
                Order::STATUS_CANCELLED,
            ])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('admin.orders.history', [
            'orders' => $orders,
        ]);
    }

    public function show(Request $request, string $orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)
            ->firstOrFail();
        
        $order->load('table', 'user', 'items.options');

        return view('admin.orders.show', [
            'order' => $order,
            'paymentSetting' => PaymentSetting::current(),
        ]);
    }

    public function poll(Request $request)
    {
        $validated = $request->validate([
            'since' => ['nullable', 'date'],
        ]);

        $since = isset($validated['since']) ? Carbon::parse($validated['since']) : now()->subMinutes(10);

        $orders = Order::query()
            ->with('items.options')
            ->where('updated_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'server_time' => now()->toISOString(),
            'orders' => $orders,
        ]);
    }

    public function updateStatus(Request $request, string $orderNumber, RealtimeClient $realtime, PointsService $points)
    {
        $order = Order::where('order_number', $orderNumber)
            ->firstOrFail();
        
        $paymentSetting = PaymentSetting::current();

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                Order::STATUS_NEW,
                Order::STATUS_ACCEPTED,
                Order::STATUS_PREPARING,
                Order::STATUS_COMPLETED,
                Order::STATUS_CANCELLED,
            ])],
        ]);

        $requiresPaidBeforeComplete =
            (bool) ($paymentSetting?->require_paid_before_complete ?? false) ||
            in_array((string) ($order->order_source ?? ''), ['self_order', 'public_customer', 'qr_scan'], true);

        // Preparing: keep the existing safeguard for self_order or when setting requires it
        if (
            $validated['status'] === Order::STATUS_PREPARING &&
            $requiresPaidBeforeComplete &&
            (string) ($order->payment_status ?? '') !== 'paid'
        ) {
            $message = "Tidak bisa mengubah status ke preparing karena pembayaran masih unpaid.";
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'message' => $message], 422);
            }

            return back()->withErrors(['status' => $message])->withInput();
        }

        // Completed: always require payment to be paid before allowing complete
        if (
            $validated['status'] === Order::STATUS_COMPLETED &&
            (string) ($order->payment_status ?? '') !== 'paid'
        ) {
            $message = "Tidak bisa mengubah status ke completed karena pembayaran masih unpaid.";
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'message' => $message], 422);
            }

            return back()->withErrors(['status' => $message])->withInput();
        }

        $previousStatus = $order->status;

        $order->status = $validated['status'];
        $order->save();

        if ($previousStatus !== Order::STATUS_COMPLETED && $order->status === Order::STATUS_COMPLETED) {
            $points->awardForCompletedOrder($order);
        }

        if ($previousStatus !== Order::STATUS_CANCELLED && $order->status === Order::STATUS_CANCELLED) {
            $points->refundRedeemForCancelledOrder($order);
        }

        $order->load('items.options');
        $realtime->emit('order.updated', $order->toArray(), $order->tenant_id ?? null);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('status', 'Status order diperbarui.');
    }

    public function updatePaymentStatus(Request $request, string $orderNumber, RealtimeClient $realtime, PointsService $points)
    {
        $order = Order::where('order_number', $orderNumber)
            ->firstOrFail();
        
        $paymentSetting = PaymentSetting::current();

        $validated = $request->validate([
            'payment_status' => ['required', 'string', Rule::in(['paid', 'unpaid'])],
        ]);

        $previousPaymentStatus = (string) ($order->payment_status ?? '');
        $previousStatus = (string) ($order->status ?? '');

        $order->payment_status = $validated['payment_status'];

        // When marking unpaid from cashier, set status back to NEW
        if ($validated['payment_status'] === 'unpaid') {
            $order->status = Order::STATUS_NEW;
        }

        // When marking paid: set status based on payment method
        if (
            $validated['payment_status'] === 'paid' &&
            $previousPaymentStatus !== 'paid'
        ) {
            $paymentMethod = (string) ($order->payment_method ?? '');
            
            // For QRIS static: require manual acceptance (no auto-complete)
            if ($paymentMethod === 'qris_static') {
                $order->status = Order::STATUS_ACCEPTED;
            } elseif ($paymentMethod === 'qris_dynamic' || $paymentMethod === 'cash') {
                // For dynamic QRIS and cash: set to accepted
                $order->status = Order::STATUS_ACCEPTED;
            } elseif (isset($paymentSetting) && ($paymentSetting->auto_complete_when_paid ?? false)) {
                $order->status = Order::STATUS_COMPLETED;
            } elseif ($previousStatus === Order::STATUS_NEW) {
                $order->status = Order::STATUS_ACCEPTED;
            }
        }

        $order->save();

        // If we just completed an order, award points
        if ($previousStatus !== Order::STATUS_COMPLETED && $order->status === Order::STATUS_COMPLETED) {
            try {
                $points->awardForCompletedOrder($order);
            } catch (\Throwable $e) {
                // ignore points failure
            }
        }

        $order->load('items.options', 'table');
        $realtime->emit('order.updated', $order->toArray(), $order->tenant_id ?? null);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('status', 'Status pembayaran diperbarui.');
    }

    public function updateCustomerName(Request $request, string $orderNumber, RealtimeClient $realtime)
    {
        $order = Order::where('order_number', $orderNumber)
            ->firstOrFail();
        
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:80'],
        ]);

        $order->customer_name = $validated['customer_name'];
        $order->save();

        $order->load('items.options');
        $realtime->emit('order.updated', $order->toArray(), $order->tenant_id ?? null);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('status', 'Nama customer diperbarui.');
    }
}
