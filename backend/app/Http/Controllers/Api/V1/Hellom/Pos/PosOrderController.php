<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Listeners\Pos\OrderSideEffectsSubscriber;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\PosMember;
use App\Models\TableBill;
use App\Services\Pos\OrderService;
use App\Services\Pos\OrderStatus;
use App\Services\Pos\OutletSettings;
use App\Services\Pos\PricingException;
use App\Services\Realtime\RealtimeTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cashier order endpoints. All money and status rules live in OrderService; this
 * controller only resolves the outlet, validates input and shapes responses.
 */
class PosOrderController extends BasePosController
{
    public function __construct(private readonly OrderService $orders)
    {
    }

    /** Price a cart exactly as store() would, without saving (server totals are final). */
    public function preview(Request $request): JsonResponse
    {
        $outlet = $this->posOutlet($request);
        if (!$outlet) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        $validated = $request->validate($this->cartRules());

        try {
            $input = $this->orderInput($validated, $outlet);
            $input['preview'] = true;
            $quote = $this->orders->quote($outlet, $input);
        } catch (PricingException $e) {
            return $this->orderRuleFailed($e);
        }

        return $this->success([
            'lines' => $quote['lines'],
            'totals' => $quote['totals'],
            'redeem_points' => $quote['redeem_points'],
        ], 'Estimasi total');
    }

    public function store(Request $request): JsonResponse
    {
        $outlet = $this->posOutlet($request);
        if (!$outlet) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        $validated = $request->validate($this->cartRules());

        try {
            $input = $this->orderInput($validated, $outlet);
            $input += [
                'source' => OrderService::SOURCE_POS,
                'dining_table' => $this->resolveTable($outlet, $validated['table_id'] ?? null),
                'service_type' => $validated['service_type'] ?? 'dine_in',
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'user_id' => $request->user()?->id,
            ];
            $order = $this->orders->create($outlet, $input);
        } catch (PricingException $e) {
            return $this->orderRuleFailed($e);
        }

        return $this->success([
            'order' => $this->present($order) + ['items_count' => $order->items->count()],
        ], 'Pesanan dibuat', 201);
    }

    public function index(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }

        $status = $request->query('status');
        $query = Order::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
            ->with(['items.options', 'table']);

        if ($status && $status !== 'all') {
            $query->where('status', OrderStatus::fromInput((string) $status) ?? $status);
        }
        if ($request->filled('payment_status')) {
            $query->where('payment_status', (string) $request->query('payment_status'));
        }

        $orders = $query->orderByDesc('created_at')->limit(100)->get();

        return $this->success([
            'orders' => $orders->map(fn (Order $order) => $this->present($order, withItems: true))->values(),
            'server_time' => now()->toIso8601String(),
        ], 'Orders retrieved');
    }

    public function updateStatus(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string',
            'reason' => 'nullable|string|max:255',
        ]);
        $to = OrderStatus::fromInput($validated['status']);
        if (!$to) {
            return $this->error('Status tidak dikenal', 'INVALID_STATUS', null, 422);
        }
        // Cancelling through the status endpoint needs the same permission as POST …/cancel.
        if ($to === Order::STATUS_CANCELLED && !$this->canPos($request, 'order_cancel')) {
            return $this->error('Akun kamu belum punya akses membatalkan pesanan. Minta owner/admin mengaktifkannya di POS › Staff.', 'POS_PERMISSION_DENIED', null, 403);
        }
        $order = $this->findOrder($request, $orderId);
        if ($to === Order::STATUS_CANCELLED && blank($validated['reason'] ?? null)) {
            return $this->error('Alasan pembatalan wajib diisi', 'CANCEL_REASON_REQUIRED', null, 422);
        }

        try {
            $order = $this->orders->transition($order, $to, $request->user()?->id, $validated['reason'] ?? null);
        } catch (PricingException $e) {
            return $this->orderRuleFailed($e);
        }

        return $this->success(['order' => $this->present($order)], 'Status pesanan diperbarui');
    }

    public function cancel(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:255']);
        $order = $this->findOrder($request, $orderId);

        try {
            $order = $this->orders->cancel($order, $validated['reason'], $request->user()?->id);
        } catch (PricingException $e) {
            return $this->orderRuleFailed($e);
        }

        return $this->success(['order' => $this->present($order)], 'Pesanan dibatalkan');
    }

    /** Record payment. The kitchen status is not touched (paid ≠ completed). */
    public function pay(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', Rule::in(OrderService::PAYMENT_METHODS)],
            'payment_amount' => 'required|integer|min:0',
            'payment_note' => 'nullable|string|max:200',
        ]);
        $order = $this->findOrder($request, $orderId);

        try {
            $order = $this->orders->markPaid($order, $validated['payment_method'], (int) $validated['payment_amount'], $validated['payment_note'] ?? null, $request->user()?->id);
        } catch (PricingException $e) {
            return $this->orderRuleFailed($e);
        }

        return $this->success([
            'order' => $this->present($order) + [
                'total_amount' => (int) $order->final_amount,
                'payment_method' => $order->payment_method,
                'payment_amount' => (int) $order->payment_amount,
                'payment_change' => (int) $order->payment_change,
                'paid_at' => $order->paid_at,
            ],
            'change_amount' => (int) $order->payment_change,
        ], 'Pembayaran tercatat');
    }

    public function refund(Request $request, string $orderId): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org || !$this->canPos($request, 'order_refund')) {
            return $this->error('Akun kamu belum punya akses refund. Minta owner/admin mengaktifkannya di POS › Staff.', 'FORBIDDEN', null, 403);
        }
        $validated = $request->validate(['reason' => 'required|string|max:255']);
        $order = $this->findOrder($request, $orderId);

        try {
            $order = $this->orders->refund($order, $validated['reason'], $request->user()?->id);
        } catch (PricingException $e) {
            return $this->orderRuleFailed($e);
        }

        return $this->success(['order' => $this->present($order)], 'Refund tercatat');
    }

    /** Open table bills of the active outlet. */
    public function tableBills(Request $request): JsonResponse
    {
        $outlet = $this->posOutlet($request);
        if (!$outlet) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        $bills = TableBill::query()
            ->where('outlet_id', $outlet->id)
            ->where('status', $request->query('status', TableBill::STATUS_OPEN))
            ->orderByDesc('opened_at')
            ->limit(100)
            ->get();
        $service = app(\App\Services\Pos\TableBillService::class);

        return $this->success(['bills' => $bills->map(fn (TableBill $bill) => $service->summary($bill))->values()], 'Tagihan meja');
    }

    public function showTableBill(Request $request, int $billId): JsonResponse
    {
        $bill = $this->findBill($request, $billId);

        return $this->success(['bill' => app(\App\Services\Pos\TableBillService::class)->summary($bill)], 'Tagihan meja');
    }

    public function payTableBill(Request $request, int $billId): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', Rule::in(OrderService::PAYMENT_METHODS)],
            'payment_amount' => 'required|integer|min:0',
            'payment_note' => 'nullable|string|max:200',
        ]);
        $bill = $this->findBill($request, $billId);

        try {
            $result = $this->orders->payBill($bill, $validated['payment_method'], (int) $validated['payment_amount'], $validated['payment_note'] ?? null, $request->user()?->id);
        } catch (PricingException $e) {
            return $this->orderRuleFailed($e);
        }

        return $this->success([
            'bill' => app(\App\Services\Pos\TableBillService::class)->summary($result['bill']),
            'total' => $result['total'],
            'change_amount' => $result['change'],
        ], 'Tagihan meja lunas');
    }

    /** Socket token for this outlet's order room (sound/badge on new orders). */
    public function realtimeToken(Request $request, RealtimeTokenService $tokens): JsonResponse
    {
        $outlet = $this->posOutlet($request);
        if (!$outlet || !$request->user()) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        $room = OrderSideEffectsSubscriber::outletRoom((string) $outlet->tenant_slug, (int) $outlet->id);
        $issued = $tokens->issue($request->user(), [$room]);

        return $this->success([
            'enabled' => $issued !== null,
            'token' => $issued['token'] ?? null,
            'expires_at' => $issued['expires_at'] ?? null,
            'room' => $room,
            'poll_seconds' => 15,
        ], 'Realtime token');
    }

    public function receipt(Request $request, int $orderId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }

        $order = Order::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
            ->with(['items', 'table'])
            ->findOrFail($orderId);

        $org = $request->user()->currentOrganization;

        // Convert logo ke base64 agar tidak ada CORS issue
        $logoBase64 = null;
        if ($org->logo_path) {
            $logoFullPath = storage_path('app/public/' . $org->logo_path);
            if (file_exists($logoFullPath)) {
                $logoContent = file_get_contents($logoFullPath);
                $logoMime = mime_content_type($logoFullPath);
                $logoBase64 = 'data:' . $logoMime . ';base64,' . base64_encode($logoContent);
            }
        }

        return $this->success([
            'receipt' => [
                'order_number' => $order->order_number,
                'created_at' => $order->created_at,
                'status' => $order->status,
                'service_type' => $order->service_type,
                'table_code' => $order->table?->code,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'notes' => $order->notes,
                'items' => $order->items->map(fn($i) => [
                    'name' => $i->product_name,
                    'quantity' => $i->qty,
                    'price' => (int) $i->unit_price,
                    'subtotal' => (int) $i->line_total,
                ]),
                'total_amount' => (int) $order->total_amount,
                'discount_amount' => (int) $order->discount_amount,
                'points_discount_amount' => (int) $order->points_discount_amount,
                'service_amount' => (int) $order->service_amount,
                'tax_amount' => (int) $order->tax_amount,
                'rounding_amount' => (int) $order->rounding_amount,
                'final_amount' => (int) ($order->final_amount ?: $order->total_amount),
                'payment' => [
                    'method' => $order->payment_method,
                    'amount' => (int) $order->payment_amount,
                    'change' => (int) $order->payment_change,
                    'note' => $order->payment_note,
                    'paid_at' => $order->paid_at,
                ],
                'organization' => [
                    'name' => $org->name,
                    'logo_path' => $org->logo_path ?? null,
                    'logo_url' => $org->logo_path
                        ? url('storage/' . $org->logo_path)
                        : null,
                    'logo_base64' => $logoBase64,
                    'address' => $org->address ?? null,
                    'phone' => $org->phone ?? null,
                ],
            ],
        ], 'Receipt retrieved');
    }

    /** @return array<string, mixed> */
    private function cartRules(): array
    {
        return [
            'table_id' => 'nullable|integer',
            'customer_name' => 'nullable|string|max:100',
            'customer_phone' => 'nullable|string|max:20',
            'service_type' => 'nullable|string|in:dine_in,takeaway',
            'member_id' => 'nullable|integer',
            'reward_rule_id' => 'nullable|integer',
            'redeem_points' => 'nullable|integer|min:0',
            'confirm_member_name' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:999',
            'items.*.notes' => 'nullable|string|max:255',
            'items.*.options' => 'nullable|array',
            'items.*.options.*.option_id' => 'nullable|integer',
            'items.*.options.*.value_id' => 'nullable|integer',
            // Client-side totals (discount_amount, final_amount) are ignored: the server prices the cart.
        ];
    }

    /** @return array<string, mixed> */
    private function orderInput(array $validated, Outlet $outlet): array
    {
        $member = null;
        if (!empty($validated['member_id'])) {
            $member = PosMember::query()->forOrganization((int) $outlet->organization_id)->find($validated['member_id']);
            if (!$member) {
                throw new PricingException('Member tidak ditemukan', [], 'MEMBER_NOT_FOUND', 404);
            }
        }

        return [
            'items' => $validated['items'],
            'member' => $member,
            'reward_rule_id' => $validated['reward_rule_id'] ?? null,
            'redeem_points' => (int) ($validated['redeem_points'] ?? 0),
            'verification' => ['confirm_member_name' => $validated['confirm_member_name'] ?? null],
        ];
    }

    private function resolveTable(Outlet $outlet, mixed $tableId): ?DiningTable
    {
        if (empty($tableId)) {
            return null;
        }
        $table = DiningTable::withoutGlobalScope('tenant')
            ->where('tenant_id', $outlet->tenant_slug)
            ->find((int) $tableId);
        if (!$table) {
            throw new PricingException('Meja tidak ditemukan', [], 'TABLE_NOT_FOUND', 404);
        }
        if (!$table->outlet_id) {
            $table->forceFill(['outlet_id' => $outlet->id])->save();
        }

        return $table;
    }

    private function findOrder(Request $request, string|int $orderId): Order
    {
        return Order::withoutGlobalScope('tenant')
            ->where('tenant_id', (string) $request->attributes->get('posTenantSlug'))
            ->findOrFail((int) $orderId);
    }

    private function findBill(Request $request, int $billId): TableBill
    {
        return TableBill::query()
            ->where('tenant_id', (string) $request->attributes->get('posTenantSlug'))
            ->findOrFail($billId);
    }

    /** @return array<string, mixed> */
    private function present(Order $order, bool $withItems = false): array
    {
        $data = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'table' => $order->relationLoaded('table') && $order->table ? [
                'id' => $order->table->id,
                'code' => $order->table->code,
                'name' => $order->table->name,
            ] : null,
            'table_label' => $order->table_label,
            'table_bill_id' => $order->table_bill_id,
            'service_type' => $order->service_type,
            'order_source' => $order->order_source,
            'status' => $order->status,
            'status_label' => OrderStatus::LABELS[$order->status] ?? $order->status,
            'allowed_next' => OrderStatus::allowedNext((string) $order->status),
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'subtotal_amount' => (int) ($order->subtotal_amount ?? $order->total_amount),
            'total_amount' => (int) $order->total_amount,
            'discount_amount' => (int) $order->discount_amount,
            'points_discount_amount' => (int) $order->points_discount_amount,
            'service_amount' => (int) $order->service_amount,
            'tax_amount' => (int) $order->tax_amount,
            'rounding_amount' => (int) $order->rounding_amount,
            'final_amount' => (int) $order->final_amount,
            'redeemed_points' => (int) $order->redeemed_points,
            'points_earned' => (int) $order->points_earned,
            'member_id' => $order->member_id,
            'notes' => $order->notes,
            'cancel_reason' => $order->cancel_reason,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
        ];

        if ($withItems) {
            $data['items_count'] = $order->items->count();
            $data['items'] = $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'quantity' => $item->qty,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
                'options' => $item->relationLoaded('options')
                    ? $item->options->map(fn ($o) => $o->option_name . ': ' . $o->value_name)->values()
                    : [],
            ])->values();
        }

        return $data;
    }
}
