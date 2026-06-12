<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KitchenController extends Controller
{
    public function index(Request $request)
    {
        // Show all orders that are waiting for kitchen action.
        // If cashier marks an order as accepted, it must appear here regardless of source/payment.
        $orders = Order::with(['items.product', 'items.options', 'table'])
            ->whereIn('status', [Order::STATUS_ACCEPTED, Order::STATUS_PREPARING])
            ->orderBy('created_at', 'asc')
            ->get();

        // Group orders by status for better organization
        $acceptedOrders = $orders->where('status', Order::STATUS_ACCEPTED);
        $preparingOrders = $orders->where('status', Order::STATUS_PREPARING);

        return view('admin.kitchen.index', compact('acceptedOrders', 'preparingOrders'))->with([
            'realtimePublicUrl' => config('realtime.public_url'),
        ]);
    }

    public function updateStatus(Request $request, $orderNumber)
    {
        $request->validate([
            'status' => 'required|in:accepted,preparing,completed',
        ]);

        $order = Order::where('order_number', $orderNumber)->firstOrFail();

        // Validate status transitions
        $currentStatus = $order->status;
        $newStatus = $request->status;

        if ($currentStatus === Order::STATUS_NEW && $newStatus === Order::STATUS_PREPARING) {
            return response()->json(['error' => 'Order must be accepted first'], 400);
        }

        if ($currentStatus === Order::STATUS_COMPLETED && in_array($newStatus, [Order::STATUS_ACCEPTED, Order::STATUS_PREPARING])) {
            return response()->json(['error' => 'Cannot change status of completed order'], 400);
        }

        $order->update(['status' => $newStatus]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'order' => $order->load(['items.product', 'items.options'])
        ]);
    }

    public function show($orderNumber)
    {
        $order = Order::with(['items.product', 'items.options', 'table'])
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        return view('admin.kitchen.show', compact('order'));
    }
}