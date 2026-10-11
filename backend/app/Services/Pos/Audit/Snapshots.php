<?php

namespace App\Services\Pos\Audit;

use App\Models\Order;
use App\Models\Product;

/** Small, stable before/after states for the audit trail (integers and strings only). */
final class Snapshots
{
    /** @return array<string, mixed> */
    public static function order(Order $order, bool $withItems = true): array
    {
        $data = [
            'order_number' => (string) $order->order_number,
            'status' => (string) $order->status,
            'payment_status' => (string) $order->payment_status,
            'payment_method' => $order->payment_method ? (string) $order->payment_method : null,
            'subtotal_amount' => (int) ($order->subtotal_amount ?? $order->total_amount),
            'discount_amount' => (int) $order->discount_amount,
            'points_discount_amount' => (int) $order->points_discount_amount,
            'final_amount' => (int) $order->final_amount,
            'payment_amount' => (int) $order->payment_amount,
            'payment_change' => (int) $order->payment_change,
            'refund_amount' => (int) $order->refund_amount,
            'table_label' => $order->table_label ? (string) $order->table_label : null,
            'table_bill_id' => $order->table_bill_id ? (int) $order->table_bill_id : null,
        ];
        if ($withItems) {
            $data['items'] = $order->items()->get(['product_id', 'product_name', 'qty', 'unit_price', 'line_total'])
                ->map(fn ($i) => ['product_id' => $i->product_id ? (int) $i->product_id : null, 'name' => (string) $i->product_name,
                    'qty' => (int) $i->qty, 'unit_price' => (int) $i->unit_price, 'line_total' => (int) $i->line_total])->values()->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function product(Product $product): array
    {
        return [
            'name' => (string) $product->name,
            'category_id' => $product->category_id ? (int) $product->category_id : null,
            'price' => (int) round((float) $product->price),
            'is_available' => (bool) $product->is_available,
            'track_stock' => (bool) $product->track_stock,
            'stock' => $product->stock === null ? null : (int) $product->stock,
        ];
    }
}
