<?php

namespace App\Services\Pos;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\TableBill;

/**
 * One open bill per table: every order placed for the table — from the QR code or by
 * the cashier — joins it until the bill is settled. Counter/pickup orders have no bill.
 * Called inside the order transaction.
 */
final class TableBillService
{
    public function attach(Order $order, ?DiningTable $table): ?TableBill
    {
        if (!$table || ($table->kind ?? DiningTable::KIND_TABLE) !== DiningTable::KIND_TABLE) {
            return null;
        }

        $bill = TableBill::query()
            ->where('dining_table_id', $table->id)
            ->where('status', TableBill::STATUS_OPEN)
            ->lockForUpdate()
            ->first();

        if (!$bill) {
            $bill = TableBill::query()->create([
                'outlet_id' => $order->outlet_id,
                'tenant_id' => $order->tenant_id,
                'dining_table_id' => $table->id,
                'status' => TableBill::STATUS_OPEN,
                'opened_at' => now(),
            ]);
        }

        $order->forceFill(['table_bill_id' => $bill->id])->save();

        return $bill;
    }

    /** Close the bill once nothing on it is left to pay. */
    public function settleIfDone(?int $billId): void
    {
        if (!$billId) {
            return;
        }
        $bill = TableBill::query()->lockForUpdate()->find($billId);
        if (!$bill || $bill->status !== TableBill::STATUS_OPEN) {
            return;
        }

        $orders = Order::withoutGlobalScope('tenant')->where('table_bill_id', $bill->id)->get(['id', 'status', 'payment_status']);
        $live = $orders->where('status', '!=', Order::STATUS_CANCELLED);
        if ($orders->isEmpty()) {
            return;
        }
        if ($live->isEmpty()) {
            $bill->forceFill(['status' => TableBill::STATUS_CANCELLED, 'closed_at' => now()])->save();
        } elseif ($live->every(fn ($o) => $o->payment_status !== Order::PAYMENT_UNPAID && $o->payment_status !== null)) {
            $bill->forceFill(['status' => TableBill::STATUS_PAID, 'closed_at' => now()])->save();
        }
    }

    /** @return array<string,mixed> */
    public function summary(TableBill $bill): array
    {
        $orders = Order::withoutGlobalScope('tenant')
            ->where('table_bill_id', $bill->id)
            ->with('items')
            ->orderBy('id')
            ->get();
        $live = $orders->where('status', '!=', Order::STATUS_CANCELLED);
        $unpaid = $live->filter(fn (Order $o) => !$o->isPaid() && $o->payment_status !== Order::PAYMENT_REFUNDED);

        return [
            'id' => $bill->id,
            'status' => $bill->status,
            'dining_table_id' => $bill->dining_table_id,
            'table_label' => $orders->first()?->table_label,
            'opened_at' => optional($bill->opened_at)->toIso8601String(),
            'closed_at' => optional($bill->closed_at)->toIso8601String(),
            'orders_count' => $live->count(),
            'total_amount' => (int) $live->sum('final_amount'),
            'paid_amount' => (int) $live->filter(fn (Order $o) => $o->isPaid())->sum('final_amount'),
            'unpaid_amount' => (int) $unpaid->sum('final_amount'),
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'order_source' => $o->order_source,
                'status' => $o->status,
                'status_label' => OrderStatus::LABELS[$o->status] ?? $o->status,
                'payment_status' => $o->payment_status,
                'final_amount' => (int) $o->final_amount,
                'items' => $o->items->map(fn ($i) => ['name' => $i->product_name, 'qty' => (int) $i->qty, 'line_total' => (int) $i->line_total])->values()->all(),
            ])->values()->all(),
        ];
    }
}
