<?php

namespace App\Services\Pos;

use App\Events\Pos\OrderConfirmed;
use App\Events\Pos\OrderCreated;
use App\Events\Pos\OrderPaid;
use App\Events\Pos\OrderRefunded;
use App\Events\Pos\OrderStatusChanged;
use App\Events\Pos\OrderVoided;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\PosMember;
use App\Models\PosRedemption;
use App\Models\PosRewardRule;
use App\Models\Product;
use App\Models\TableBill;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single order engine for the cashier and self-order.
 *
 *   quote()      price a cart exactly as create() would (preview / revalidation)
 *   create()     validate, price, persist, join the table bill → OrderCreated
 *   transition() kitchen status, forward only                   → OrderConfirmed / OrderStatusChanged
 *   markPaid()   payment only, kitchen status untouched          → OrderPaid
 *   payBill()    pay every open order on a table bill at once    → OrderPaid (each)
 *   cancel()     unpaid orders only                              → OrderVoided
 *   refund()     paid orders only (full amount)                  → OrderRefunded
 *
 * Side effects (stock, points, socket, audit, fraud) live in App\Listeners\Pos.
 * Rule violations throw PricingException (message + code + HTTP status).
 */
final class OrderService
{
    public const SOURCE_POS = 'pos';
    public const SOURCE_SELF_ORDER = 'public_customer';

    public const PAYMENT_METHODS = ['cash', 'transfer', 'qris', 'gopay', 'dana', 'other'];

    public function __construct(
        private readonly PricingService $pricing,
        private readonly LoyaltyService $loyalty,
        private readonly TableBillService $bills,
    ) {
    }

    /**
     * Price a cart for an outlet.
     *
     * $input: items[{product_id, quantity, options?, notes?, expected_unit_price?}],
     *         member?: PosMember, reward_rule_id?: int, redeem_points?: int, verification?: array
     *
     * When items carry expected_unit_price (what the customer saw), any difference, missing
     * product or stock shortage is reported together as CART_CHANGED (409) so the page can
     * show exactly what changed.
     *
     * @return array{lines:list<array>, totals:array, reward_rule:?PosRewardRule, redeem_points:int, member:?PosMember}
     */
    public function quote(Outlet $outlet, array $input, bool $lock = false): array
    {
        $items = array_values((array) ($input['items'] ?? []));
        if ($items === []) {
            throw new PricingException('Keranjang kosong.', [], 'CART_EMPTY');
        }

        $products = $this->loadProducts($outlet, $items, $lock);
        $this->assertAvailable($items, $products);

        $priced = $this->pricing->priceLines($items, $products);
        $this->assertExpectedPrices($items, $priced['lines']);

        /** @var PosMember|null $member */
        $member = $input['member'] ?? null;
        $subtotal = (int) $priced['subtotal'];

        $rule = null;
        $rewardDiscount = 0;
        if (!empty($input['reward_rule_id'])) {
            if (!$member) {
                throw new PricingException('Reward hanya bisa dipakai oleh member.', [], 'MEMBER_REQUIRED');
            }
            $rule = $this->eligibleRewardRule($outlet, $member, (int) $input['reward_rule_id']);
            $rewardDiscount = $this->pricing->rewardDiscount($rule, $subtotal);
        }

        $redeemPoints = max(0, (int) ($input['redeem_points'] ?? 0));
        $pointsDiscount = 0;
        if ($redeemPoints > 0) {
            if (!$member) {
                throw new PricingException('Tukar poin hanya untuk member.', [], 'MEMBER_REQUIRED');
            }
            $organization = $this->organizationOf($outlet);
            $pointsDiscount = $this->loyalty->validateRedemption(
                $member,
                $this->loyalty->settingsFor($organization),
                $redeemPoints,
                $subtotal - $rewardDiscount,
                0,
                (array) ($input['verification'] ?? []),
                verify: empty($input['preview'])
            );
        }

        return [
            'lines' => $priced['lines'],
            'totals' => $this->pricing->totals(OutletSettings::for($outlet), $subtotal, $rewardDiscount, $pointsDiscount),
            'reward_rule' => $rule,
            'redeem_points' => $redeemPoints,
            'member' => $member,
        ];
    }

    /**
     * $input (on top of quote()): source, dining_table?: DiningTable, service_type, customer_name,
     * customer_phone, notes, payment_method?, user_id?, auto_confirm?: bool
     */
    public function create(Outlet $outlet, array $input): Order
    {
        $table = $input['dining_table'] ?? null;
        if ($table instanceof DiningTable && ((int) $table->outlet_id !== (int) $outlet->id || (string) $table->tenant_id !== (string) $outlet->tenant_slug)) {
            throw new PricingException('Meja tidak ditemukan di outlet ini.', [], 'TABLE_NOT_FOUND', 404);
        }

        return DB::transaction(function () use ($outlet, $input, $table) {
            if (!empty($input['member']) && (int) ($input['redeem_points'] ?? 0) > 0) {
                // Lock the member first: concurrent redemptions queue here.
                $input['member'] = PosMember::query()->lockForUpdate()->findOrFail($input['member']->id);
            }

            $quote = $this->quote($outlet, $input, lock: true);
            $totals = $quote['totals'];
            $member = $quote['member'];
            $userId = $input['user_id'] ?? null;
            $autoConfirm = (bool) ($input['auto_confirm'] ?? false);

            $order = Order::query()->create([
                'tenant_id' => $outlet->tenant_slug,
                'outlet_id' => $outlet->id,
                'member_id' => $member?->id,
                'dining_table_id' => $table?->id,
                'table_label' => $table ? ($table->name ?: $table->code) : null,
                'user_id' => $userId,
                'customer_name' => $input['customer_name'] ?? $member?->name,
                'customer_phone' => $input['customer_phone'] ?? null,
                'service_type' => $input['service_type'] ?? 'dine_in',
                'order_source' => $input['source'] ?? self::SOURCE_POS,
                'status' => $autoConfirm ? Order::STATUS_ACCEPTED : Order::STATUS_NEW,
                'confirmed_at' => $autoConfirm ? now() : null,
                'payment_status' => Order::PAYMENT_UNPAID,
                'total_amount' => $totals['subtotal'],
                'subtotal_amount' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'points_discount_amount' => $totals['points_discount_amount'],
                'service_amount' => $totals['service_amount'],
                'tax_amount' => $totals['tax_amount'],
                'rounding_amount' => $totals['rounding_amount'],
                'final_amount' => $totals['final_amount'],
                'points_earned' => 0,
                'points_redeemed' => $quote['redeem_points'],
                'redeemed_points' => $quote['redeem_points'],
                'notes' => $input['notes'] ?? null,
                'payment_meta' => ['pricing' => array_intersect_key($totals, array_flip(['service_percent', 'tax_percent', 'rounding_step']))],
            ]);

            if (!empty($input['payment_method'])) {
                // Customer's preferred method; otherwise the column default applies.
                $order->forceFill(['payment_method' => $input['payment_method']])->saveQuietly();
            }

            foreach ($quote['lines'] as $line) {
                $optionRows = $line['options'];
                $item = $order->items()->create([
                    'product_id' => $line['product_id'],
                    'product_name' => $line['product_name'],
                    'unit_price' => $line['unit_price'],
                    'base_unit_price' => $line['base_unit_price'],
                    'options_total' => $line['options_total'],
                    'qty' => $line['qty'],
                    'line_total' => $line['line_total'],
                    'selected_options' => $line['selected_options'],
                ]);
                foreach ($optionRows as $row) {
                    $item->options()->create($row);
                }
            }

            $this->bills->attach($order, $table);

            if ($member && $quote['reward_rule']) {
                PosRedemption::query()->create([
                    'outlet_id' => $outlet->id,
                    'tenant_id' => $outlet->tenant_slug,
                    'member_id' => $member->id,
                    'order_id' => $order->id,
                    'reward_rule_id' => $quote['reward_rule']->id,
                    'points_used' => 0,
                    'discount_amount' => $totals['discount_amount'],
                    'status' => 'applied',
                ]);
            }

            if ($member && $quote['redeem_points'] > 0) {
                $this->loyalty->redeemForOrder($member, $order, $quote['redeem_points'], "Tukar poin untuk pesanan {$order->order_number}", $userId, 'redeem:order:' . $order->id);
                $member->save();
            }

            $order->load('items');
            OrderCreated::dispatch($order, $userId, ['source' => $order->order_source]);
            if ($autoConfirm) {
                OrderConfirmed::dispatch($order, $userId, ['auto' => true]);
            }

            return $order;
        });
    }

    public function transition(Order $order, string $to, ?int $userId = null, ?string $reason = null): Order
    {
        if ($to === Order::STATUS_CANCELLED) {
            return $this->cancel($order, $reason ?: 'Dibatalkan', $userId);
        }

        return DB::transaction(function () use ($order, $to, $userId) {
            $locked = $this->lock($order);
            $from = (string) $locked->status;
            if (!OrderStatus::canTransition($from, $to)) {
                throw new PricingException(
                    sprintf('Status tidak bisa diubah dari "%s" ke "%s".', OrderStatus::LABELS[$from] ?? $from, OrderStatus::LABELS[$to] ?? $to),
                    [], 'ILLEGAL_TRANSITION'
                );
            }

            $confirming = $locked->confirmed_at === null;
            $locked->forceFill(['status' => $to, 'confirmed_at' => $locked->confirmed_at ?? now()])->save();

            $context = ['from' => $from, 'to' => $to];
            if ($confirming) {
                OrderConfirmed::dispatch($locked, $userId, $context);
            }
            if (!$confirming || $to !== Order::STATUS_ACCEPTED) {
                OrderStatusChanged::dispatch($locked, $userId, $context);
            }

            return $locked;
        });
    }

    public function cancel(Order $order, string $reason, ?int $userId = null): Order
    {
        return DB::transaction(function () use ($order, $reason, $userId) {
            $locked = $this->lock($order);
            if ($locked->isPaid()) {
                throw new PricingException('Pesanan sudah dibayar. Gunakan refund untuk membatalkannya.', [], 'ORDER_ALREADY_PAID');
            }
            if (OrderStatus::isFinal((string) $locked->status)) {
                throw new PricingException('Pesanan sudah ' . strtolower(OrderStatus::LABELS[$locked->status] ?? $locked->status) . '.', [], 'ILLEGAL_TRANSITION');
            }

            $from = (string) $locked->status;
            $locked->forceFill([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancel_reason' => mb_substr($reason, 0, 255),
            ])->save();
            $this->bills->settleIfDone($locked->table_bill_id);

            OrderVoided::dispatch($locked, $userId, ['from' => $from, 'reason' => $reason]);

            return $locked;
        });
    }

    /** Record payment. Does not change the kitchen status. */
    public function markPaid(Order $order, string $method, int $amount, ?string $note = null, ?int $userId = null): Order
    {
        return DB::transaction(function () use ($order, $method, $amount, $note, $userId) {
            $locked = $this->lock($order);
            $this->assertPayable($locked);
            $final = (int) $locked->final_amount;
            if ($method === 'cash' && $amount < $final) {
                throw new PricingException('Jumlah bayar kurang dari total pesanan.', [['total' => $final, 'paid' => $amount, 'kurang' => $final - $amount]], 'PAYMENT_INSUFFICIENT');
            }

            $meta = (array) ($locked->payment_meta ?? []);
            $meta['paid_by'] = $userId;
            $locked->forceFill([
                'payment_meta' => $meta,
                'payment_method' => $method,
                'payment_amount' => $amount,
                'payment_change' => max(0, $amount - $final),
                'payment_note' => $note,
                'payment_status' => Order::PAYMENT_PAID,
                'paid_at' => now(),
            ])->save();
            $this->bills->settleIfDone($locked->table_bill_id);

            OrderPaid::dispatch($locked, $userId, ['method' => $method]);

            return $locked;
        });
    }

    /**
     * Pay every unpaid order on the bill in one go.
     *
     * @return array{bill: TableBill, orders: Collection<int, Order>, total: int, change: int}
     */
    public function payBill(TableBill $bill, string $method, int $amount, ?string $note = null, ?int $userId = null): array
    {
        return DB::transaction(function () use ($bill, $method, $amount, $note, $userId) {
            $bill = TableBill::query()->lockForUpdate()->findOrFail($bill->id);
            if ($bill->status !== TableBill::STATUS_OPEN) {
                throw new PricingException('Tagihan meja ini sudah ditutup.', [], 'BILL_CLOSED');
            }
            $orders = Order::withoutGlobalScope('tenant')
                ->where('table_bill_id', $bill->id)
                ->where('status', '!=', Order::STATUS_CANCELLED)
                ->where('payment_status', Order::PAYMENT_UNPAID)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($orders->isEmpty()) {
                throw new PricingException('Tidak ada pesanan yang belum dibayar di meja ini.', [], 'BILL_NOTHING_DUE');
            }
            $total = (int) $orders->sum('final_amount');
            if ($method === 'cash' && $amount < $total) {
                throw new PricingException('Jumlah bayar kurang dari total tagihan.', [['total' => $total, 'paid' => $amount, 'kurang' => $total - $amount]], 'PAYMENT_INSUFFICIENT');
            }
            $change = max(0, $amount - $total);

            foreach ($orders as $order) {
                $meta = (array) ($order->payment_meta ?? []);
                $meta['paid_by'] = $userId;
                $meta['table_bill'] = ['id' => $bill->id, 'tendered' => $amount, 'total' => $total, 'change' => $change];
                $order->forceFill([
                    'payment_method' => $method,
                    'payment_amount' => (int) $order->final_amount,
                    'payment_change' => 0,
                    'payment_note' => $note,
                    'payment_status' => Order::PAYMENT_PAID,
                    'paid_at' => now(),
                    'payment_meta' => $meta,
                ])->save();
                OrderPaid::dispatch($order, $userId, ['method' => $method, 'table_bill_id' => $bill->id]);
            }
            $this->bills->settleIfDone($bill->id);

            return ['bill' => $bill->fresh(), 'orders' => $orders, 'total' => $total, 'change' => $change];
        });
    }

    /** Full refund of a paid order; kitchen status is left as is. */
    public function refund(Order $order, string $reason, ?int $userId = null): Order
    {
        return DB::transaction(function () use ($order, $reason, $userId) {
            $locked = $this->lock($order);
            if (!$locked->isPaid()) {
                throw new PricingException('Hanya pesanan yang sudah dibayar yang bisa direfund.', [], 'ORDER_NOT_PAID');
            }
            $meta = (array) ($locked->payment_meta ?? []);
            $meta['refund_reason'] = $reason;
            $locked->forceFill([
                'payment_status' => Order::PAYMENT_REFUNDED,
                'refunded_at' => now(),
                'refund_amount' => (int) $locked->final_amount,
                'payment_meta' => $meta,
            ])->save();

            OrderRefunded::dispatch($locked, $userId, ['reason' => $reason, 'amount' => (int) $locked->final_amount]);

            return $locked;
        });
    }

    public function organizationOf(Outlet $outlet): Organization
    {
        return $outlet->relationLoaded('organization') && $outlet->organization
            ? $outlet->organization
            : Organization::query()->findOrFail($outlet->organization_id);
    }

    /** Active reward rules of the organization this member currently qualifies for. */
    public function eligibleRewardRules(Outlet $outlet, PosMember $member): Collection
    {
        $organization = $this->organizationOf($outlet);

        return PosRewardRule::query()
            ->whereIn('tenant_id', array_unique([LoyaltyService::organizationSlug($organization), (string) $outlet->tenant_slug]))
            ->where('is_active', true)
            ->get()
            ->filter(fn (PosRewardRule $rule) => match ($rule->trigger_type) {
                'points_threshold' => (int) $member->total_points >= (int) $rule->trigger_value,
                'orders_threshold' => (int) $member->total_orders >= (int) $rule->trigger_value,
                'spend_threshold' => (int) $member->total_spent >= (int) $rule->trigger_value,
                default => false,
            })
            ->values();
    }

    private function eligibleRewardRule(Outlet $outlet, PosMember $member, int $ruleId): PosRewardRule
    {
        $rule = $this->eligibleRewardRules($outlet, $member)->firstWhere('id', $ruleId);
        if (!$rule) {
            throw new PricingException('Reward tidak tersedia untuk member ini.', [], 'REWARD_NOT_ELIGIBLE');
        }

        return $rule;
    }

    private function lock(Order $order): Order
    {
        return Order::withoutGlobalScope('tenant')->lockForUpdate()->findOrFail($order->id);
    }

    private function assertPayable(Order $order): void
    {
        if ($order->isPaid()) {
            throw new PricingException('Pesanan ini sudah dibayar.', [], 'ORDER_ALREADY_PAID');
        }
        if ($order->status === Order::STATUS_CANCELLED) {
            throw new PricingException('Pesanan sudah dibatalkan.', [], 'ORDER_CANCELLED');
        }
        if ($order->payment_status === Order::PAYMENT_REFUNDED) {
            throw new PricingException('Pesanan sudah direfund.', [], 'ORDER_REFUNDED');
        }
    }

    /** @return Collection<int, Product> keyed by id */
    private function loadProducts(Outlet $outlet, array $items, bool $lock): Collection
    {
        $ids = collect($items)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values();

        return Product::withoutGlobalScope('tenant')
            ->where('tenant_id', $outlet->tenant_slug)
            ->whereIn('id', $ids)
            ->with(['options.values'])
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get()
            ->keyBy('id');
    }

    private function assertAvailable(array $items, Collection $products): void
    {
        $wanted = [];
        foreach ($items as $item) {
            $wanted[(int) $item['product_id']] = ($wanted[(int) $item['product_id']] ?? 0) + max(1, (int) $item['quantity']);
        }

        $problems = [];
        foreach ($wanted as $productId => $qty) {
            $product = $products[$productId] ?? null;
            if (!$product) {
                $problems[] = ['product_id' => $productId, 'reason' => 'not_found', 'message' => 'Menu sudah tidak tersedia'];
            } elseif (!$product->is_available) {
                $problems[] = ['product_id' => $productId, 'name' => $product->name, 'reason' => 'unavailable', 'message' => "{$product->name} sedang tidak tersedia"];
            } elseif ($product->track_stock && (int) $product->stock < $qty) {
                $stock = max(0, (int) $product->stock);
                $problems[] = ['product_id' => $productId, 'name' => $product->name, 'reason' => 'stock', 'available' => $stock,
                    'message' => $stock > 0 ? "Stok {$product->name} tinggal {$stock}" : "Stok {$product->name} habis"];
            }
        }

        if ($problems !== []) {
            throw new PricingException($problems[0]['message'], $problems, 'CART_CHANGED', 409);
        }
    }

    private function assertExpectedPrices(array $items, array $lines): void
    {
        $problems = [];
        foreach ($items as $index => $item) {
            if (!isset($item['expected_unit_price']) || !isset($lines[$index])) {
                continue;
            }
            $expected = (int) $item['expected_unit_price'];
            $actual = (int) $lines[$index]['unit_price'];
            if ($expected !== $actual) {
                $problems[] = ['index' => $index, 'product_id' => $lines[$index]['product_id'], 'name' => $lines[$index]['product_name'],
                    'reason' => 'price', 'old_price' => $expected, 'new_price' => $actual,
                    'message' => "Harga {$lines[$index]['product_name']} berubah"];
            }
        }
        if ($problems !== []) {
            throw new PricingException('Harga beberapa menu berubah. Periksa lagi keranjangmu.', $problems, 'CART_CHANGED', 409);
        }
    }
}
