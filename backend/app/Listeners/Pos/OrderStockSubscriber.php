<?php

namespace App\Listeners\Pos;

use App\Events\Pos\OrderCreated;
use App\Events\Pos\OrderVoided;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Events\Dispatcher;

/**
 * Stock follows the order: reserved when it is created (inside the order transaction,
 * products already locked by OrderService) and put back once when it is voided.
 * A refund does not return stock — the food was served.
 */
final class OrderStockSubscriber
{
    public function reserve(OrderCreated $event): void
    {
        $this->apply($event->order, -1, 'stock_reserved');
    }

    public function restore(OrderVoided $event): void
    {
        $order = $event->order;
        $meta = (array) ($order->payment_meta ?? []);
        if (empty($meta['stock_reserved']) || !empty($meta['stock_restored'])) {
            return;
        }
        $this->apply($order, 1, 'stock_restored');
    }

    private function apply(Order $order, int $direction, string $flag): void
    {
        $quantities = $order->items()->get(['product_id', 'qty'])
            ->groupBy('product_id')
            ->map(fn ($rows) => (int) $rows->sum('qty'));

        foreach ($quantities as $productId => $qty) {
            $query = Product::withoutGlobalScope('tenant')
                ->whereKey($productId)
                ->where('tenant_id', $order->tenant_id)
                ->where('track_stock', true);
            $direction < 0 ? $query->decrement('stock', $qty) : $query->increment('stock', $qty);
        }

        $meta = (array) ($order->payment_meta ?? []);
        $meta[$flag] = true;
        $order->forceFill(['payment_meta' => $meta])->saveQuietly();
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderCreated::class => 'reserve',
            OrderVoided::class => 'restore',
        ];
    }
}
