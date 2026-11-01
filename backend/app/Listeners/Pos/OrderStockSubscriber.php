<?php

namespace App\Listeners\Pos;

use App\Events\Pos\OrderCreated;
use App\Events\Pos\OrderRefunded;
use App\Events\Pos\OrderVoided;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Services\Pos\StockMovementService;
use Illuminate\Events\Dispatcher;

/**
 * Stock is reserved when an order is created and restored on void. Refunds restore
 * stock for non-F&B businesses; F&B keeps its existing served-food behavior.
 */
final class OrderStockSubscriber
{
    public function __construct(private readonly StockMovementService $movements)
    {
    }

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
        $this->restoreSerials($order);
        $this->apply($order, 1, 'stock_restored');
    }

    public function restoreRefunded(OrderRefunded $event): void
    {
        $order = $event->order;
        $meta = (array) ($order->payment_meta ?? []);
        if (empty($meta['stock_reserved']) || !empty($meta['stock_refunded'])) {
            return;
        }

        $outlet = Outlet::withTrashed()->find($order->outlet_id);
        $organization = $outlet?->organization
            ?? Organization::query()->where('pos_tenant_slug', $order->tenant_id)->first();
        if (!$organization) {
            throw new \RuntimeException('Profil usaha untuk transaksi refund tidak ditemukan.');
        }
        if ($organization->business_type === 'fnb') {
            return;
        }

        $this->restoreSerials($order);
        $this->apply($order, 1, 'stock_refunded');
    }

    private function restoreSerials(Order $order): void
    {
        $serialIds = $order->items()->whereNotNull('product_serial_id')->pluck('product_serial_id');
        if ($serialIds->isNotEmpty()) {
            ProductSerial::query()
                ->where('tenant_id', $order->tenant_id)
                ->whereIn('id', $serialIds)
                ->where('sold_order_id', $order->id)
                ->update(['status' => 'available', 'sold_order_id' => null, 'sold_at' => null]);
        }
    }

    private function apply(Order $order, int $direction, string $flag): void
    {
        $meta = (array) ($order->payment_meta ?? []);
        $items = $order->items()->with('variant')->get();
        $groups = $items->groupBy(fn ($item) => $item->product_id . ':' . ($item->product_variant_id ?? ''));
        foreach ($groups as $rows) {
            $item = $rows->first();
            $product = Product::withoutGlobalScope('tenant')
                ->where('tenant_id', $order->tenant_id)
                ->find($item->product_id);
            if (!$product || (!$product->track_stock && !$item->variant?->track_stock)) {
                continue;
            }

            $referenceType = $flag === 'stock_refunded' ? 'order_refund' : 'order';
            $this->movements->record(
                $product,
                $direction * (int) $rows->sum('qty'),
                $direction < 0 ? 'sale' : 'return',
                $order->outlet_id,
                $item->variant,
                $referenceType,
                $order->id,
                match ($flag) {
                    'stock_reserved' => 'Stok dicadangkan untuk transaksi',
                    'stock_refunded' => 'Stok dikembalikan karena refund',
                    default => 'Stok dikembalikan karena transaksi dibatalkan',
                },
                $order->user_id,
                $this->movementUuid($order->id, $product->id, $item->product_variant_id, $direction, $referenceType),
                createdAtClient: $order->created_at_client?->toDateTimeString(),
                allowNegativeStock: (!empty($meta['offline_sync']) || !empty($meta['offline_created'])) && $direction < 0,
            );
        }
        $meta[$flag] = true;
        $order->forceFill(['payment_meta' => $meta])->saveQuietly();
    }

    private function movementUuid(int $orderId, int $productId, ?int $variantId, int $direction, string $referenceType): string
    {
        $hash = hash('sha256', "{$referenceType}:{$orderId}:product:{$productId}:variant:" . ($variantId ?? 0) . ":direction:{$direction}");

        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderCreated::class => 'reserve',
            OrderVoided::class => 'restore',
            OrderRefunded::class => 'restoreRefunded',
        ];
    }
}
