<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\Order;
use App\Models\PosLoyaltySetting;
use App\Models\Product;
use App\Models\PosMember;
use App\Models\PosPointTransaction;
use App\Models\PosRedemption;
use App\Models\PosRewardRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosOrderController extends BasePosController
{
    public function store(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('Kontekst POS tidak tersedia', 'CONTEXT_MISSING');
        }

        \Log::info('Order creation request', [
            'tenant' => $tenantSlug,
            'body' => $request->all(),
            'user_id' => $request->user()?->id,
        ]);

        $validated = $request->validate([
            'table_id' => 'nullable|integer',
            'customer_name' => 'nullable|string|max:100',
            'customer_phone' => 'nullable|string|max:20',
            'service_type' => 'nullable|string|in:dine_in,takeaway',
            'member_id' => 'nullable|integer',
            'reward_rule_id' => 'nullable|integer',
            'discount_amount' => 'nullable|integer|min:0',
            'final_amount' => 'nullable|integer|min:0',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
            'items.*.options' => 'nullable|array',
            'items.*.options.*.option_id' => 'nullable|integer',
            'items.*.options.*.value_id' => 'nullable|integer',
        ]);

        // Validate table_id belongs to tenant if provided
        if (!empty($validated['table_id'])) {
            $tableExists = \App\Models\DiningTable::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantSlug)
                ->where('id', $validated['table_id'])
                ->exists();

            if (!$tableExists) {
                return $this->error('Meja tidak ditemukan', 'TABLE_NOT_FOUND');
            }
        }

        $member = null;
        if (!empty($validated['member_id'])) {
            $member = PosMember::where('tenant_id', $tenantSlug)
                ->find($validated['member_id']);

            if (!$member) {
                return $this->error('Member tidak ditemukan', 'MEMBER_NOT_FOUND', null, 404);
            }
        }

        DB::beginTransaction();
        try {
            // Calculate total and validate products
            $total = 0;
            $orderItems = [];

            $productIds = collect($validated['items'])->pluck('product_id')->unique();
            $products = Product::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantSlug)
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                return $this->error('Produk tidak ditemukan untuk tenant ini', 'PRODUCT_NOT_FOUND');
            }

            foreach ($validated['items'] as $item) {
                $product = $products[$item['product_id']] ?? null;

                if (!$product || !$product->is_available) {
                    return $this->error("Produk dengan ID {$item['product_id']} tidak ditemukan atau tidak tersedia", 'PRODUCT_NOT_FOUND');
                }

                if ($product->track_stock) {
                    $available = (int) ($product->stock ?? 0);
                    if ($available <= 0) {
                        return $this->error("Stok habis untuk: {$product->name}", 'OUT_OF_STOCK');
                    }
                    if ((int) $item['quantity'] > $available) {
                        return $this->error("Stok tidak cukup untuk: {$product->name}", 'OUT_OF_STOCK');
                    }
                }

                $optionsTotal = 0;
                $optionsData = [];

                // Process add-ons if provided
                if (isset($item['options']) && is_array($item['options'])) {
                    foreach ($item['options'] as $optionData) {
                        $option = $product->options()->where('id', $optionData['option_id'])->first();
                        if ($option) {
                            $value = $option->values()->where('id', $optionData['value_id'])->first();
                            if ($value) {
                                $optionsTotal += $value->price_delta;
                                $optionsData[] = [
                                    'option_name' => $option->name,
                                    'value_name' => $value->name,
                                    'price_delta' => $value->price_delta,
                                ];
                            }
                        }
                    }
                }

                $unitPrice = $product->price + $optionsTotal;
                $subtotal = $unitPrice * $item['quantity'];
                $total += $subtotal;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $unitPrice,
                    'base_unit_price' => $product->price,
                    'options_total' => $optionsTotal,
                    'qty' => $item['quantity'],
                    'line_total' => $subtotal,
                    'selected_options' => $item['options'] ?? [],
                    'options' => $optionsData,
                ];
            }

            $rewardRule = null;
            $discountAmount = 0;
            if (!empty($validated['reward_rule_id'])) {
                if (!$member) {
                    return $this->error('Reward hanya bisa dipakai oleh member', 'MEMBER_REQUIRED', null, 422);
                }

                $rewardRule = PosRewardRule::where('tenant_id', $tenantSlug)
                    ->where('is_active', true)
                    ->find($validated['reward_rule_id']);

                if (!$rewardRule) {
                    return $this->error('Reward rule tidak ditemukan', 'REWARD_RULE_NOT_FOUND', null, 404);
                }

                $discountAmount = $this->calculateRewardDiscount($rewardRule, $total);
            }

            $finalAmount = max(0, $total - $discountAmount);

            // Get table info if provided
            $tableLabel = null;
            if (!empty($validated['table_id'])) {
                $table = \App\Models\DiningTable::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantSlug)
                    ->find($validated['table_id']);
                if ($table) {
                    $tableLabel = $table->name ?: $table->code;
                }
            }

            // Create order
            $order = Order::create([
                'tenant_id' => $tenantSlug,
                'member_id' => $member?->id,
                'dining_table_id' => $validated['table_id'] ?? null,
                'table_label' => $tableLabel,
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'service_type' => $validated['service_type'] ?? 'dine_in',
                'order_source' => 'pos',
                'status' => Order::STATUS_NEW,
                'payment_status' => 'unpaid',
                'total_amount' => $total,
                'points_earned' => 0,
                'points_redeemed' => 0,
                'discount_amount' => $discountAmount,
                'redeemed_points' => 0,
                'final_amount' => $finalAmount,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Create order items
            foreach ($orderItems as $itemData) {
                $optionsData = $itemData['options'] ?? [];
                unset($itemData['options']);

                $orderItem = $order->items()->create($itemData);

                // Create order item options
                foreach ($optionsData as $optionData) {
                    $orderItem->options()->create($optionData);
                }
            }

            // Decrement stock for tracked products
            foreach ($validated['items'] as $item) {
                $product = $products[$item['product_id']];
                if ($product->track_stock) {
                    $product->stock = (int) ($product->stock ?? 0) - (int) $item['quantity'];
                    $product->save();
                }
            }

            if ($member && $rewardRule) {
                PosRedemption::create([
                    'tenant_id' => $tenantSlug,
                    'member_id' => $member->id,
                    'order_id' => $order->id,
                    'reward_rule_id' => $rewardRule->id,
                    'points_used' => 0,
                    'discount_amount' => $discountAmount,
                    'status' => 'applied',
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => [
                    'order' => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'total_amount' => $order->total_amount,
                        'discount_amount' => $order->discount_amount,
                        'final_amount' => $order->final_amount,
                        'items_count' => count($validated['items']),
                        'customer_name' => $order->customer_name,
                        'customer_phone' => $order->customer_phone,
                        'member_id' => $order->member_id,
                        'table_label' => $order->table_label,
                        'service_type' => $order->service_type,
                    ]
                ],
                'message' => 'Order created successfully! 🎉',
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            // LOG DETAIL ERROR
            \Log::error('Order creation failed', [
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'tenant'    => $tenantSlug ?? 'unknown',
                'trace'     => $e->getTraceAsString(),
                'request'   => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(), // ← tampilkan error asli sementara
                'error'   => [
                    'code'   => 'ORDER_CREATE_FAILED',
                    'detail' => $e->getMessage(),
                    'file'   => $e->getFile(),
                    'line'   => $e->getLine(),
                ],
            ], 400);
        }
    }

    private function awardPointsForOrder(string $tenantSlug, Order $order): void
    {
        $member = PosMember::where('tenant_id', $tenantSlug)->find($order->member_id);
        if (!$member) {
            return;
        }

        $spendAmount = max(0, (int) ($order->final_amount ?: ($order->total_amount - $order->discount_amount)));
        $settings = PosLoyaltySetting::currentForTenant($tenantSlug);
        $basePoints = $this->calculatePointsToEarn($settings, $spendAmount);

        DB::transaction(function () use ($tenantSlug, $order, $member, $basePoints, $spendAmount) {
            $rewardRule = PosRedemption::where('tenant_id', $tenantSlug)
                ->where('order_id', $order->id)
                ->with('rewardRule')
                ->latest('id')
                ->first()?->rewardRule;

            $bonusPoints = $rewardRule?->reward_type === 'bonus_points'
                ? max(0, (int) $rewardRule->reward_value)
                : 0;
            $totalPointsEarned = $basePoints + $bonusPoints;

            if ($basePoints > 0) {
                PosPointTransaction::create([
                    'tenant_id'     => $tenantSlug,
                    'member_id'     => $member->id,
                    'order_id'      => $order->id,
                    'type'          => 'earn',
                    'points'        => $basePoints,
                    'balance_after' => $member->total_points + $basePoints,
                    'description'   => "Poin dari pesanan {$order->order_number}",
                ]);
            }

            if ($bonusPoints > 0) {
                PosPointTransaction::create([
                    'tenant_id'     => $tenantSlug,
                    'member_id'     => $member->id,
                    'order_id'      => $order->id,
                    'type'          => 'bonus',
                    'points'        => $bonusPoints,
                    'balance_after' => $member->total_points + $totalPointsEarned,
                    'description'   => "Bonus reward dari pesanan {$order->order_number}",
                ]);
            }

            $member->increment('total_points', $totalPointsEarned);
            $member->increment('redeemable_points', $totalPointsEarned);
            $member->increment('total_orders');
            $member->increment('total_spent', $spendAmount);
            $member->update(['last_order_at' => now()]);

            $order->update([
                'points_earned' => $totalPointsEarned,
                'final_amount' => $spendAmount,
            ]);
        });
    }

    public function index(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('Kontekst POS tidak tersedia', 'CONTEXT_MISSING');
        }

        $status = $request->query('status'); // Optional status filter

        $query = Order::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
            ->with(['items', 'table']);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $orders = $query->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return $this->success([
            'orders' => $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer_name,
                    'table' => $order->table ? [
                        'id' => $order->table->id,
                        'code' => $order->table->code,
                        'name' => $order->table->name,
                    ] : null,
                    'table_label' => $order->table_label,
                    'service_type' => $order->service_type,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'total_amount' => $order->total_amount,
                    'discount_amount' => $order->discount_amount,
                    'final_amount' => $order->final_amount,
                    'member_id' => $order->member_id,
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                    'items_count' => $order->items->count(),
                    'items' => $order->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'product_name' => $item->product_name,
                            'quantity' => $item->qty,
                            'unit_price' => $item->unit_price,
                            'line_total' => $item->line_total,
                        ];
                    }),
                ];
            }),
        ], 'Orders retrieved');
    }

    public function updateStatus(Request $request, string $orderId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('Kontekst POS tidak tersedia', 'CONTEXT_MISSING');
        }

        $validated = $request->validate([
            'status' => 'required|string|in:new,accepted,preparing,prepared,completed,cancelled',
        ]);

        $order = Order::where('tenant_id', $tenantSlug)->findOrFail($orderId);

        // Award poin jika order selesai dan ada member
        if ($validated['status'] === 'completed'
            && $order->member_id
            && $order->status !== Order::STATUS_COMPLETED) {
            $this->awardPointsForOrder($tenantSlug, $order);
        }

        $order->update(['status' => $validated['status']]);

        return $this->success(['order' => $order], 'Order status updated');
    }

    public function receipt(Request $request, int $orderId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('Kontekst POS tidak tersedia', 'CONTEXT_MISSING');
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

    private function calculateRewardDiscount(PosRewardRule $rule, int $totalAmount): int
    {
        return match ($rule->reward_type) {
            'discount_percent' => (int) round($totalAmount * $rule->reward_value / 100),
            'discount_fixed' => min($rule->reward_value, $totalAmount),
            'free_product' => (int) (Product::withoutGlobalScope('tenant')->find($rule->reward_product_id)?->price ?? 0),
            'bonus_points' => 0,
            default => 0,
        };
    }

    private function calculatePointsToEarn(PosLoyaltySetting $settings, int $amount): int
    {
        if (!$settings->enabled) {
            return 0;
        }

        if ($amount < (int) $settings->min_spend_amount) {
            return 0;
        }

        $points = (int) floor($amount / max(1, (int) $settings->points_per_amount));

        if ($settings->max_points_per_order !== null) {
            $points = min($points, (int) $settings->max_points_per_order);
        }

        return max(0, $points);
    }
}
