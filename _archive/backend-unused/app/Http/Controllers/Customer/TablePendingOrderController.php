<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\Order;
use Illuminate\Http\Request;

class TablePendingOrderController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'table' => ['required', 'string', 'max:32'],
        ]);

        $table = DiningTable::query()
            ->where('public_id', $validated['table'])
            ->where('is_active', true)
            ->firstOrFail();

        $recentCompletedCutoff = now()->subMinutes(5);

        $order = Order::query()
            ->with('items.options')
            ->where('dining_table_id', $table->id)
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->where(function ($q) use ($recentCompletedCutoff) {
                $q->where('status', '!=', Order::STATUS_COMPLETED)
                    ->orWhere('updated_at', '>=', $recentCompletedCutoff);
            })
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'server_time' => now()->toISOString(),
            'order' => $order ? $this->shape($order) : null,
        ]);
    }

    private function shape(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'status' => $order->status,
            'total_amount' => (int) $order->total_amount,
            'created_at' => $order->created_at?->toISOString(),
            'updated_at' => $order->updated_at?->toISOString(),
            'items' => $order->items->map(function ($it) {
                return [
                    'product_name' => $it->product_name,
                    'qty' => (int) $it->qty,
                    'unit_price' => (int) $it->unit_price,
                    'line_total' => (int) $it->line_total,
                    'options' => $it->options->map(fn ($o) => [
                        'option_name' => $o->option_name,
                        'value_name' => $o->value_name,
                    ])->values(),
                ];
            })->values(),
        ];
    }
}
