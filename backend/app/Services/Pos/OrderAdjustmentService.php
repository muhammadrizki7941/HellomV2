<?php

namespace App\Services\Pos;

use App\Events\Pos\OrderStatusChanged;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\TableBill;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Changes to an order after it was placed (cashier only), every one on UNPAID orders except the
 * payment-method change:
 *
 *   changeItemQty()        qty up/down, 0 = remove the line; stock follows; totals repriced
 *   setManualDiscount()    percent / fixed cashier discount (owner PIN in the controller)
 *   changePaymentMethod()  paid orders: cash ↔ non-cash (owner PIN in the controller)
 *   split()                move some items to a new order on the same table/bill ("pisah bill")
 *   transferOrder()        move one order to another table
 *   moveBill()             move a table's unpaid orders to another table, merging into its open bill
 *
 * Paid orders are never moved, split or edited: that is the "move a cash-paid bill to an empty
 * table and void it" pattern. Totals always come from PricingService with the rates stored on the
 * order. Callers wrap these in their own transaction together with the audit log entry.
 */
final class OrderAdjustmentService
{
    public const DISCOUNT_TYPES = ['percent', 'fixed'];

    public function __construct(
        private readonly PricingService $pricing,
        private readonly TableBillService $bills,
    ) {
    }

    /** Whether a receipt was printed for the order (reductions then need the owner PIN). */
    public static function receiptPrinted(Order $order): bool
    {
        return (int) (((array) ($order->payment_meta ?? []))['receipt_prints'] ?? 0) > 0;
    }

    /** @return array{order: Order, item: array{id:int, name:string, from:int, to:int, unit_price:int}} */
    public function changeItemQty(Order $order, int $itemId, int $qty, ?int $userId = null): array
    {
        return DB::transaction(function () use ($order, $itemId, $qty, $userId) {
            $locked = $this->lockEditable($order);
            /** @var OrderItem|null $item */
            $item = $locked->items()->lockForUpdate()->find($itemId);
            if (!$item) {
                throw new PricingException('Item tidak ditemukan di pesanan ini.', [], 'ITEM_NOT_FOUND', 404);
            }
            $from = (int) $item->qty;
            $qty = max(0, $qty);
            if ($qty === $from) {
                throw new PricingException('Jumlahnya sama, tidak ada yang diubah.', [], 'NOTHING_CHANGED');
            }
            if ($qty === 0 && $locked->items()->count() <= 1) {
                throw new PricingException('Ini item terakhir. Kalau pesanan tidak jadi, batalkan pesanannya.', [], 'LAST_ITEM');
            }

            $this->moveStock($locked, (int) $item->product_id, $qty - $from);
            $snapshot = ['id' => (int) $item->id, 'name' => (string) $item->product_name, 'from' => $from, 'to' => $qty, 'unit_price' => (int) $item->unit_price];
            if ($qty === 0) {
                $item->options()->delete();
                $item->delete();
            } else {
                $item->forceFill(['qty' => $qty, 'line_total' => (int) $item->unit_price * $qty])->save();
            }
            $this->reprice($locked);
            $this->announce($locked, $userId, 'items_changed');

            return ['order' => $locked, 'item' => $snapshot];
        });
    }

    /** $type null = remove the discount. @return array{order: Order, from: int, to: int} */
    public function setManualDiscount(Order $order, ?string $type, int $value, ?int $userId = null): array
    {
        return DB::transaction(function () use ($order, $type, $value, $userId) {
            $locked = $this->lockEditable($order);
            $from = (int) $locked->manual_discount_amount;
            $meta = (array) ($locked->payment_meta ?? []);
            if ($type === null) {
                if ($from === 0 && empty($meta['manual_discount'])) {
                    throw new PricingException('Pesanan ini tidak punya diskon manual.', [], 'NOTHING_CHANGED');
                }
                unset($meta['manual_discount']);
            } else {
                if (!in_array($type, self::DISCOUNT_TYPES, true) || $value < 1 || ($type === 'percent' && $value > 100)) {
                    throw new PricingException('Nilai diskon tidak valid.', [], 'DISCOUNT_INVALID');
                }
                $base = $this->discountBase($locked);
                if ($type === 'fixed' && $value > $base) {
                    throw new PricingException('Diskon melebihi total pesanan (maks. Rp ' . number_format($base, 0, ',', '.') . ').', [], 'DISCOUNT_TOO_LARGE');
                }
                $meta['manual_discount'] = ['type' => $type, 'value' => $value, 'set_by' => $userId, 'at' => now()->toIso8601String()];
            }
            $locked->forceFill(['payment_meta' => $meta]);
            $this->reprice($locked);
            $this->announce($locked, $userId, 'discount_changed');

            return ['order' => $locked, 'from' => $from, 'to' => (int) $locked->manual_discount_amount];
        });
    }

    /** @return array{order: Order, from: string} */
    public function changePaymentMethod(Order $order, string $method, string $reason, ?int $userId = null): array
    {
        return DB::transaction(function () use ($order, $method, $reason, $userId) {
            $locked = Order::withoutGlobalScope('tenant')->lockForUpdate()->findOrFail($order->id);
            if ($locked->payment_status !== Order::PAYMENT_PAID) {
                throw new PricingException('Metode bayar hanya bisa diganti untuk pesanan yang sudah lunas.', [], 'ORDER_NOT_PAID');
            }
            if (!in_array($method, OrderService::PAYMENT_METHODS, true)) {
                throw new PricingException('Metode bayar tidak dikenal.', [], 'PAYMENT_METHOD_INVALID');
            }
            $from = (string) $locked->payment_method;
            if ($from === $method) {
                throw new PricingException('Metodenya sama dengan sebelumnya.', [], 'NOTHING_CHANGED');
            }
            $meta = (array) ($locked->payment_meta ?? []);
            $meta['method_changes'] = array_values(array_merge((array) ($meta['method_changes'] ?? []), [[
                'from' => $from, 'to' => $method, 'reason' => mb_substr($reason, 0, 255), 'by' => $userId, 'at' => now()->toIso8601String(),
            ]]));
            $locked->forceFill([
                'payment_method' => $method,
                // The tendered amount is unknown after the fact: record the exact total, no change.
                'payment_amount' => (int) $locked->final_amount,
                'payment_change' => 0,
                'payment_meta' => $meta,
            ])->save();
            $this->announce($locked, $userId, 'payment_method_changed');

            return ['order' => $locked, 'from' => $from];
        });
    }

    /**
     * Move part of an order to a new order on the same table/bill.
     *
     * @param list<array{item_id:int, qty:int}> $moves
     * @return array{source: Order, created: Order, moved: list<array{name:string, qty:int}>}
     */
    public function split(Order $order, array $moves, ?int $userId = null): array
    {
        return DB::transaction(function () use ($order, $moves, $userId) {
            $locked = $this->lockEditable($order);
            $items = $locked->items()->lockForUpdate()->get()->keyBy('id');
            $wanted = [];
            foreach ($moves as $move) {
                $id = (int) ($move['item_id'] ?? 0);
                $qty = (int) ($move['qty'] ?? 0);
                if ($qty < 1) {
                    continue;
                }
                $item = $items[$id] ?? null;
                if (!$item) {
                    throw new PricingException('Item tidak ditemukan di pesanan ini.', [], 'ITEM_NOT_FOUND', 404);
                }
                $wanted[$id] = min((int) $item->qty, ($wanted[$id] ?? 0) + $qty);
            }
            $totalQty = (int) $items->sum('qty');
            $movedQty = array_sum($wanted);
            if ($movedQty < 1) {
                throw new PricingException('Pilih item yang mau dipisah.', [], 'SPLIT_EMPTY');
            }
            if ($movedQty >= $totalQty) {
                throw new PricingException('Sisakan minimal satu item di pesanan asal.', [], 'SPLIT_ALL');
            }

            $meta = (array) ($locked->payment_meta ?? []);
            $created = Order::query()->create([
                'tenant_id' => $locked->tenant_id,
                'outlet_id' => $locked->outlet_id,
                'dining_table_id' => $locked->dining_table_id,
                'table_label' => $locked->table_label,
                'user_id' => $userId ?? $locked->user_id,
                'customer_name' => $locked->customer_name,
                'customer_phone' => $locked->customer_phone,
                'service_type' => $locked->service_type,
                'order_source' => OrderService::SOURCE_POS,
                'status' => $locked->status,
                'confirmed_at' => $locked->confirmed_at,
                'payment_status' => Order::PAYMENT_UNPAID,
                'total_amount' => 0, 'subtotal_amount' => 0, 'discount_amount' => 0, 'points_discount_amount' => 0,
                'service_amount' => 0, 'tax_amount' => 0, 'rounding_amount' => 0, 'final_amount' => 0,
                'points_earned' => 0, 'points_redeemed' => 0, 'redeemed_points' => 0,
                'notes' => 'Pisahan dari pesanan #' . $locked->order_number,
                // Stock for these items was already taken by the original order.
                'payment_meta' => array_filter([
                    'pricing' => $meta['pricing'] ?? null,
                    'stock_reserved' => $meta['stock_reserved'] ?? null,
                    'split_from' => (int) $locked->id,
                ], fn ($v) => $v !== null),
            ]);
            $created->forceFill(['table_bill_id' => $locked->table_bill_id, 'split_from_order_id' => $locked->id])->save();

            $moved = [];
            foreach ($wanted as $id => $qty) {
                /** @var OrderItem $item */
                $item = $items[$id];
                $moved[] = ['name' => (string) $item->product_name, 'qty' => $qty, 'line_total' => (int) $item->unit_price * $qty];
                if ($qty === (int) $item->qty) {
                    $item->forceFill(['order_id' => $created->id])->save();
                    continue;
                }
                $item->forceFill(['qty' => (int) $item->qty - $qty, 'line_total' => (int) $item->unit_price * ((int) $item->qty - $qty)])->save();
                $copy = $created->items()->create([
                    'product_id' => $item->product_id, 'product_name' => $item->product_name, 'unit_price' => $item->unit_price,
                    'base_unit_price' => $item->base_unit_price, 'options_total' => $item->options_total, 'qty' => $qty,
                    'line_total' => (int) $item->unit_price * $qty, 'selected_options' => $item->selected_options,
                ]);
                foreach ($item->options()->get() as $option) {
                    $copy->options()->create($option->only(['product_option_id', 'product_option_value_id', 'option_name', 'value_name', 'price_delta']));
                }
            }

            $this->reprice($locked);
            $this->reprice($created);
            $this->announce($locked, $userId, 'split');
            $this->announce($created, $userId, 'split');

            return ['source' => $locked, 'created' => $created, 'moved' => $moved];
        });
    }

    /** @return array{order: Order, from: ?string, to: string} */
    public function transferOrder(Order $order, DiningTable $table, ?int $userId = null): array
    {
        return DB::transaction(function () use ($order, $table, $userId) {
            $locked = $this->lockEditable($order);
            $this->assertTableOf($locked->outlet_id, $locked->tenant_id, $table);
            if ((int) $locked->dining_table_id === (int) $table->id) {
                throw new PricingException('Pesanan sudah di meja ini.', [], 'SAME_TABLE');
            }
            $from = $locked->table_label;
            $oldBill = $locked->table_bill_id ? (int) $locked->table_bill_id : null;
            $this->placeOnTable($locked, $table);
            $this->closeOrSettle($oldBill, $locked->table_bill_id ? (int) $locked->table_bill_id : null);
            $this->announce($locked, $userId, 'table_transferred');

            return ['order' => $locked, 'from' => $from, 'to' => $this->labelOf($table)];
        });
    }

    /**
     * Move every unpaid order of a bill to another table. If that table already has an open bill
     * the orders join it ("merged"), otherwise they start a new one ("moved"). Paid orders stay.
     *
     * @return array{mode: string, target_bill_id: ?int, orders: Collection<int, Order>, from: ?string, to: string}
     */
    public function moveBill(TableBill $bill, DiningTable $table, ?int $userId = null): array
    {
        return DB::transaction(function () use ($bill, $table, $userId) {
            $bill = TableBill::query()->lockForUpdate()->findOrFail($bill->id);
            if ($bill->status !== TableBill::STATUS_OPEN) {
                throw new PricingException('Tagihan meja ini sudah ditutup.', [], 'BILL_CLOSED');
            }
            $this->assertTableOf($bill->outlet_id, $bill->tenant_id, $table);
            if ((int) $bill->dining_table_id === (int) $table->id) {
                throw new PricingException('Tagihan sudah di meja ini.', [], 'SAME_TABLE');
            }
            if (($table->kind ?? DiningTable::KIND_TABLE) !== DiningTable::KIND_TABLE) {
                throw new PricingException('Tagihan hanya bisa dipindah ke meja, bukan kasir/counter.', [], 'TABLE_KIND');
            }
            $orders = Order::withoutGlobalScope('tenant')->where('table_bill_id', $bill->id)
                ->where('status', '!=', Order::STATUS_CANCELLED)->where('payment_status', Order::PAYMENT_UNPAID)
                ->orderBy('id')->lockForUpdate()->get();
            if ($orders->isEmpty()) {
                throw new PricingException('Tidak ada pesanan belum dibayar di meja ini.', [], 'BILL_NOTHING_DUE');
            }
            $merging = TableBill::query()->where('dining_table_id', $table->id)->where('status', TableBill::STATUS_OPEN)->lockForUpdate()->exists();
            $from = $orders->first()->table_label;
            foreach ($orders as $order) {
                $this->placeOnTable($order, $table);
            }
            $targetBillId = $orders->first()->table_bill_id ? (int) $orders->first()->table_bill_id : null;
            $this->closeOrSettle((int) $bill->id, $targetBillId);
            foreach ($orders as $order) {
                $this->announce($order, $userId, $merging ? 'bill_merged' : 'bill_moved');
            }

            return ['mode' => $merging ? 'merged' : 'moved', 'target_bill_id' => $targetBillId, 'orders' => $orders, 'from' => $from, 'to' => $this->labelOf($table)];
        });
    }

    /** Recompute totals from the items with the rates stored on the order. */
    public function reprice(Order $order): Order
    {
        $subtotal = (int) $order->items()->sum('line_total');
        $base = $this->discountBase($order, $subtotal);
        $rule = (array) (((array) ($order->payment_meta ?? []))['manual_discount'] ?? []);
        $manual = match ($rule['type'] ?? null) {
            'percent' => (int) round($base * (int) $rule['value'] / 100),
            'fixed' => min((int) $rule['value'], $base),
            default => 0,
        };
        $totals = $this->pricing->totals($this->settingsFor($order), $subtotal, (int) $order->discount_amount, (int) $order->points_discount_amount, $manual);
        $order->forceFill([
            'total_amount' => $totals['subtotal'],
            'subtotal_amount' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'points_discount_amount' => $totals['points_discount_amount'],
            'manual_discount_amount' => $totals['manual_discount_amount'],
            'service_amount' => $totals['service_amount'],
            'tax_amount' => $totals['tax_amount'],
            'rounding_amount' => $totals['rounding_amount'],
            'final_amount' => $totals['final_amount'],
        ])->save();

        return $order;
    }

    /** What a manual discount applies to: subtotal after reward and points discounts. */
    private function discountBase(Order $order, ?int $subtotal = null): int
    {
        $subtotal ??= (int) $order->items()->sum('line_total');
        $reward = max(0, min((int) $order->discount_amount, $subtotal));
        $points = max(0, min((int) $order->points_discount_amount, $subtotal - $reward));

        return max(0, $subtotal - $reward - $points);
    }

    private function settingsFor(Order $order): OutletSettings
    {
        $pricing = (array) (((array) ($order->payment_meta ?? []))['pricing'] ?? []);
        if (isset($pricing['tax_percent'], $pricing['service_percent'])) {
            $outlet = (new Outlet())->forceFill(['settings' => ['pricing' => [
                'tax_percent' => (float) $pricing['tax_percent'], 'service_percent' => (float) $pricing['service_percent'], 'rounding' => (int) ($pricing['rounding_step'] ?? 0),
            ]]]);

            return OutletSettings::for($outlet);
        }

        return OutletSettings::for(Outlet::query()->find($order->outlet_id));
    }

    private function lockEditable(Order $order): Order
    {
        $locked = Order::withoutGlobalScope('tenant')->lockForUpdate()->findOrFail($order->id);
        if ($locked->payment_status === Order::PAYMENT_REFUNDED) {
            throw new PricingException('Pesanan sudah direfund.', [], 'ORDER_REFUNDED');
        }
        if ($locked->isPaid()) {
            throw new PricingException('Pesanan sudah dibayar, jadi tidak bisa diubah, dipisah, atau dipindah.', [], 'ORDER_ALREADY_PAID');
        }
        if ($locked->status === Order::STATUS_CANCELLED) {
            throw new PricingException('Pesanan sudah dibatalkan.', [], 'ORDER_CANCELLED');
        }

        return $locked;
    }

    /** $delta > 0 takes more stock, < 0 puts it back. Only when the order reserved stock. */
    private function moveStock(Order $order, int $productId, int $delta): void
    {
        $meta = (array) ($order->payment_meta ?? []);
        if ($delta === 0 || empty($meta['stock_reserved']) || !empty($meta['stock_restored'])) {
            return;
        }
        $product = Product::withoutGlobalScope('tenant')->where('tenant_id', $order->tenant_id)->lockForUpdate()->find($productId);
        if (!$product) {
            if ($delta > 0) {
                throw new PricingException('Menu ini sudah tidak tersedia.', [], 'PRODUCT_NOT_FOUND');
            }

            return;
        }
        if ($delta > 0 && !$product->is_available) {
            throw new PricingException("{$product->name} sedang tidak tersedia.", [], 'CART_CHANGED', 409);
        }
        if (!$product->track_stock) {
            return;
        }
        if ($delta > 0 && (int) $product->stock < $delta) {
            $left = max(0, (int) $product->stock);
            throw new PricingException($left > 0 ? "Stok {$product->name} tinggal {$left}." : "Stok {$product->name} habis.", [], 'CART_CHANGED', 409);
        }
        $delta > 0 ? $product->decrement('stock', $delta) : $product->increment('stock', -$delta);
    }

    private function assertTableOf(?int $outletId, ?string $tenantId, DiningTable $table): void
    {
        if ((int) $table->outlet_id !== (int) $outletId || (string) $table->tenant_id !== (string) $tenantId || !$table->is_active) {
            throw new PricingException('Meja tujuan tidak ditemukan di outlet ini.', [], 'TABLE_NOT_FOUND', 404);
        }
    }

    private function placeOnTable(Order $order, DiningTable $table): void
    {
        $order->forceFill(['dining_table_id' => $table->id, 'table_label' => $this->labelOf($table), 'table_bill_id' => null])->save();
        $this->bills->attach($order, $table);
    }

    private function labelOf(DiningTable $table): string
    {
        return (string) ($table->name ?: $table->code);
    }

    /** Old bill after orders left it: closed as "merged" when empty, otherwise settled as usual. */
    private function closeOrSettle(?int $billId, ?int $mergedInto): void
    {
        if (!$billId) {
            return;
        }
        $bill = TableBill::query()->lockForUpdate()->find($billId);
        if (!$bill || $bill->status !== TableBill::STATUS_OPEN) {
            return;
        }
        if (!Order::withoutGlobalScope('tenant')->where('table_bill_id', $billId)->exists()) {
            $bill->forceFill(['status' => TableBill::STATUS_MERGED, 'merged_into_bill_id' => $mergedInto, 'closed_at' => now()])->save();

            return;
        }
        $this->bills->settleIfDone($billId);
    }

    private function announce(Order $order, ?int $userId, string $what): void
    {
        OrderStatusChanged::dispatch($order, $userId, ['from' => (string) $order->status, 'to' => (string) $order->status, 'adjustment' => $what]);
    }
}
