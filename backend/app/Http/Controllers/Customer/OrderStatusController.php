<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderStatusController extends Controller
{
    public function show(Request $request, Order $order)
    {
        $order->loadMissing('table');

        return response()->json([
            'server_time' => now()->toISOString(),
            'order' => [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'updated_at' => $order->updated_at?->toISOString(),
                'created_at' => $order->created_at?->toISOString(),
                'table' => $order->table ? [
                    'public_id' => $order->table->public_id,
                    'label' => $order->table_label,
                ] : null,
            ],
        ]);
    }
}
