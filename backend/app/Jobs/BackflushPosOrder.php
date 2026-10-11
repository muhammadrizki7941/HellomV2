<?php

namespace App\Jobs;

use App\Models\Hpp\HppProduct;
use App\Models\Hpp\HppProductVariant;
use App\Models\Hpp\HppSetting;
use App\Models\Hpp\HppStockMovement;
use App\Models\Order;
use App\Models\OrderItemOption;
use App\Models\Outlet;
use App\Services\Hpp\CostEngine;
use App\Services\Hpp\FifoCosting;
use App\Services\Hpp\StockLedger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Kalkulator HPP backflush: a paid POS order takes its ingredients out of stock by recipe —
 * sub-recipes expanded, plus the extra ingredients of chosen modifiers (POS option values linked
 * to HPP variants). Runs in the queue after the payment commits (the till never waits), once per
 * order (movements carry ref pos_order:{id}). Unpaid/cancelled orders take nothing; a refund
 * does not return ingredients (the food was made), same as POS product stock.
 */
class BackflushPosOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $orderId)
    {
        $this->afterCommit();
    }

    public function handle(StockLedger $stock, FifoCosting $fifo): void
    {
        $order = Order::withoutGlobalScope('tenant')->with('items')->find($this->orderId);
        if (!$order || $order->payment_status !== Order::PAYMENT_PAID || !$order->outlet_id) {
            return;
        }
        $organizationId = (int) Outlet::query()->whereKey($order->outlet_id)->value('organization_id');
        if (!$organizationId) {
            return;
        }
        $settings = HppSetting::query()->find($organizationId);
        if (!$settings || !$settings->backflush_enabled) {
            return;
        }
        $posIds = $order->items->pluck('product_id')->filter()->unique()->values()->all();
        $linked = HppProduct::query()->where('organization_id', $organizationId)->whereIn('pos_product_id', $posIds ?: [0])
            ->where('is_archived', false)->pluck('id', 'pos_product_id');
        if ($linked->isEmpty()) {
            return;
        }
        $optionValues = OrderItemOption::query()->whereIn('order_item_id', $order->items->pluck('id'))->get(['order_item_id', 'product_option_value_id'])
            ->groupBy('order_item_id')->map(fn ($rows) => $rows->pluck('product_option_value_id')->filter()->map(fn ($id) => (int) $id)->all());

        $engine = CostEngine::for($organizationId);
        $usage = [];
        foreach ($order->items as $item) {
            $productId = $linked[$item->product_id] ?? null;
            if (!$productId) {
                continue;
            }
            $chosen = $optionValues[$item->id] ?? [];
            $variantIds = $chosen ? HppProductVariant::query()->where('product_id', $productId)->whereIn('pos_option_value_id', $chosen)->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
            foreach ($engine->consumption((int) $productId, (float) $item->qty, $variantIds) as $ingredientId => $qty) {
                $usage[$ingredientId] = ($usage[$ingredientId] ?? 0) + $qty;
            }
        }
        if ($usage === []) {
            return;
        }

        DB::transaction(function () use ($order, $organizationId, $usage, $engine, $settings, $stock, $fifo) {
            // Once per order, even if the job runs twice.
            Order::withoutGlobalScope('tenant')->whereKey($order->id)->lockForUpdate()->first();
            if (HppStockMovement::query()->where('ref_type', 'pos_order')->where('ref_id', $order->id)->exists()) {
                return;
            }
            foreach ($usage as $ingredientId => $qty) {
                $unitCost = $engine->ingredientUnitCost((int) $ingredientId);
                if ($settings->price_method === 'fifo') {
                    $taken = $fifo->consume((int) $ingredientId, $qty);
                    $unitCost = $qty > 0 && $taken > 0 ? $taken / $qty : $unitCost;
                }
                $stock->move($organizationId, (int) $ingredientId, (int) $order->outlet_id, -$qty, 'sale', $unitCost, 'pos_order', (int) $order->id, 'Pesanan #' . $order->order_number);
            }
        });
    }
}
