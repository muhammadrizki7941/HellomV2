<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\PosMember;
use App\Models\ProductSerial;
use App\Models\PosSyncChange;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\Pos\OrderService;
use App\Services\Pos\PricingException;
use App\Services\Pos\StockMovementService;
use App\Support\Pos\BusinessProfile;
use App\Models\PosStaff;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PosSyncController extends BasePosController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly StockMovementService $stockMovements,
    ) {
    }

    public function orders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'orders' => 'required|array|min:1|max:50',
        ]);
        $results = [];

        foreach ($validated['orders'] as $payload) {
            $payload = is_array($payload) ? $payload : [];
            $itemValidator = Validator::make($payload, [
                'client_uuid' => 'required|uuid',
                'created_at_client' => 'required|date',
                'outlet_id' => 'required|integer|min:1',
                'table_id' => 'nullable|integer|min:1',
                'customer_name' => 'nullable|string|max:100',
                'customer_phone' => 'nullable|string|max:20',
                'service_type' => 'nullable|string|in:dine_in,takeaway',
                'member_id' => 'nullable|integer|min:1',
                'reward_rule_id' => 'nullable|integer|min:1',
                'redeem_points' => 'nullable|integer|min:0',
                'confirm_member_name' => 'nullable|string|max:100',
                'notes' => 'nullable|string|max:500',
                'items' => 'required|array|min:1|max:100',
                'items.*.product_id' => 'required|integer|min:1',
                'items.*.product_variant_id' => 'nullable|integer|min:1',
                'items.*.quantity' => 'required|integer|min:1|max:999',
                'items.*.options' => 'nullable|array',
                'items.*.options.*.option_id' => 'nullable|integer|min:1',
                'items.*.options.*.value_id' => 'nullable|integer|min:1',
                'items.*.serial_numbers' => 'nullable|array|max:999',
                'items.*.serial_numbers.*' => 'required|string|max:100',
                'payment_method' => 'nullable|string|in:cash,transfer,qris,gopay,dana,other',
                'payment_amount' => 'nullable|integer|min:0',
                'payment_note' => 'nullable|string|max:500',
                'paid_manually' => 'nullable|boolean',
                'paid_at_client' => 'nullable|date',
                'offline_created' => 'nullable|boolean',
            ]);

            if ($itemValidator->fails()) {
                $results[] = [
                    'client_uuid' => is_array($payload) ? ($payload['client_uuid'] ?? null) : null,
                    'status' => 'rejected',
                    'code' => 'VALIDATION_FAILED',
                    'reason' => $itemValidator->errors()->first(),
                    'errors' => $itemValidator->errors()->toArray(),
                ];
                continue;
            }

            $item = $itemValidator->validated();
            $outlet = $this->authorizedOutlet($request, (int) $item['outlet_id']);
            if (!$outlet) {
                $results[] = [
                    'client_uuid' => $item['client_uuid'],
                    'status' => 'rejected',
                    'code' => 'POS_OUTLET_NOT_ASSIGNED',
                    'reason' => 'Akun ini tidak memiliki akses ke outlet transaksi.',
                ];
                continue;
            }

            $tenantId = (string) $outlet->tenant_slug;
            $existing = Order::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('client_uuid', $item['client_uuid'])
                ->first();
            if ($existing) {
                $results[] = $this->duplicateResult($existing, $item['client_uuid']);
                continue;
            }

            try {
                $result = DB::transaction(function () use ($request, $item, $outlet, $tenantId) {
                    if (!empty($item['table_id'])) {
                        $organization = $this->getOrg($request);
                        if (!$organization || !BusinessProfile::hasModule($organization, 'tables_selforder')) {
                            throw new PricingException('Modul meja & self-order tidak aktif untuk usaha ini.', [], 'POS_MODULE_DISABLED', 403);
                        }
                    }

                    $member = null;
                    if (!empty($item['member_id'])) {
                        $member = PosMember::query()
                            ->forOrganization((int) $outlet->organization_id)
                            ->find((int) $item['member_id']);
                        if (!$member) {
                            throw new PricingException('Member tidak ditemukan.', [], 'MEMBER_NOT_FOUND', 404);
                        }
                    }

                    $diningTable = null;
                    if (!empty($item['table_id'])) {
                        $diningTable = DiningTable::withoutGlobalScope('tenant')
                            ->where('tenant_id', $tenantId)
                            ->where('outlet_id', $outlet->id)
                            ->find((int) $item['table_id']);
                        if (!$diningTable) {
                            throw new PricingException('Meja tidak ditemukan di outlet ini.', [], 'TABLE_NOT_FOUND', 404);
                        }
                    }

                    $order = $this->orders->create($outlet, [
                        'items' => $item['items'],
                        'client_uuid' => $item['client_uuid'],
                        'created_at_client' => Carbon::parse($item['created_at_client'])->utc(),
                        'source' => OrderService::SOURCE_POS,
                        'dining_table' => $diningTable,
                        'service_type' => $item['service_type'] ?? 'dine_in',
                        'customer_name' => $item['customer_name'] ?? null,
                        'customer_phone' => $item['customer_phone'] ?? null,
                        'notes' => $item['notes'] ?? null,
                        'member' => $member,
                        'reward_rule_id' => $item['reward_rule_id'] ?? null,
                        'redeem_points' => (int) ($item['redeem_points'] ?? 0),
                        'verification' => ['confirm_member_name' => $item['confirm_member_name'] ?? null],
                        'user_id' => $request->user()?->id,
                        'allow_negative_stock' => true,
                        'offline_sync' => true,
                        'offline_created' => (bool) ($item['offline_created'] ?? true),
                    ]);

                    if (!$order->wasRecentlyCreated) {
                        return ['duplicate' => $order->fresh()];
                    }

                    if (!empty($item['payment_method'])) {
                        $amount = (int) ($item['payment_amount'] ?? $order->final_amount);
                        $order = $this->orders->markPaid(
                            $order,
                            (string) $item['payment_method'],
                            $amount,
                            $item['payment_note'] ?? null,
                            $request->user()?->id,
                            [
                                'paid_manually' => (bool) ($item['paid_manually'] ?? false),
                                'paid_at_client' => $item['paid_at_client'] ?? null,
                                'offline_created' => (bool) ($item['offline_created'] ?? true),
                                'offline_sync' => true,
                                'created_at_client' => $item['created_at_client'],
                                'sync_received_at' => now()->toIso8601String(),
                            ],
                        );
                    }

                    $warnings = $this->negativeStockWarnings($order->fresh('items'), $outlet);
                    if ($warnings !== []) {
                        $metadata = (array) ($order->payment_meta ?? []);
                        $metadata['negative_stock_review'] = $warnings;
                        $order->forceFill(['payment_meta' => $metadata])->saveQuietly();
                    }

                    $this->recordActivity($request, 'offline_order_synced', [
                        'target_type' => 'order',
                        'target_id' => $order->id,
                        'target_label' => '#' . $order->order_number,
                        'client_uuid' => $item['client_uuid'],
                        'created_at_client' => $item['created_at_client'],
                        'synced_at' => now()->toIso8601String(),
                        'negative_stock' => $warnings !== [],
                    ]);

                    return ['order' => $order->fresh(), 'warnings' => $warnings];
                });

                if (isset($result['duplicate'])) {
                    $results[] = $this->duplicateResult($result['duplicate'], $item['client_uuid']);
                } else {
                    $results[] = [
                        'client_uuid' => $item['client_uuid'],
                        'status' => 'ok',
                        'order' => [
                            'id' => (int) $result['order']->id,
                            'order_number' => (string) $result['order']->order_number,
                            'payment_status' => (string) $result['order']->payment_status,
                            'final_amount' => (int) $result['order']->final_amount,
                            'created_at_client' => $result['order']->created_at_client?->toIso8601String(),
                            'synced_at' => $result['order']->created_at?->toIso8601String(),
                        ],
                        'warnings' => $result['warnings'],
                    ];
                }
            } catch (PricingException $exception) {
                $results[] = [
                    'client_uuid' => $item['client_uuid'],
                    'status' => 'rejected',
                    'code' => $exception->errorCode,
                    'reason' => $exception->getMessage(),
                ];
            } catch (QueryException $exception) {
                $existing = Order::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('client_uuid', $item['client_uuid'])
                    ->first();
                if ($existing) {
                    $results[] = $this->duplicateResult($existing, $item['client_uuid']);
                    continue;
                }
                Log::error('POS offline order sync database error.', [
                    'user_id' => $request->user()?->id,
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outlet->id,
                    'client_uuid' => $item['client_uuid'],
                    'exception' => $exception,
                ]);
                throw $exception;
            }
        }

        return $this->success(['results' => $results, 'server_time' => now()->toIso8601String()], 'Antrean transaksi diproses');
    }

    public function pull(Request $request): JsonResponse
    {
        $validated = $request->validate(['since' => 'nullable|date']);
        $outlet = $this->posOutlet($request);
        $organization = $this->getOrg($request);
        if (!$outlet || !$organization) {
            return $this->error('Konteks POS tidak tersedia.', 'CONTEXT_MISSING', null, 403);
        }

        $tenantId = (string) $outlet->tenant_slug;
        $since = isset($validated['since']) ? Carbon::parse($validated['since'])->utc() : null;
        $isInitial = $since === null;
        $serverTime = now()->utc();
        $querySince = $since?->copy()->subSeconds(2);

        $changedProductIds = Product::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('updated_at', '<=', $serverTime)
            ->when(!$isInitial, fn ($query) => $query->where('updated_at', '>=', $querySince))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
        $changedVariantProductIds = ProductVariant::query()
            ->where('tenant_id', $tenantId)
            ->where('updated_at', '<=', $serverTime)
            ->when(!$isInitial, fn ($query) => $query->where('updated_at', '>=', $querySince))
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id);
        $changedStockProductIds = $isInitial
            ? collect()
            : StockMovement::query()
                ->where('tenant_id', $tenantId)
                ->where('outlet_id', $outlet->id)
                ->whereBetween('created_at', [$querySince, $serverTime])
                ->whereNotNull('product_id')
                ->pluck('product_id')
                ->map(fn ($id) => (int) $id);
        $productIds = $isInitial
            ? $changedProductIds
            : $changedProductIds->merge($changedVariantProductIds)->merge($changedStockProductIds)->unique()->values();

        $products = Product::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('updated_at', '<=', $serverTime)
            ->whereIn('id', $productIds)
            ->with([
                'category',
                'options.values',
                'variants' => fn ($query) => $query->withSum([
                    'stockMovements as outlet_stock' => fn ($movements) => $movements->where('outlet_id', $outlet->id),
                ], 'qty'),
                'priceTiers',
            ])
            ->withSum([
                'stockMovements as outlet_stock' => fn ($query) => $query->where('outlet_id', $outlet->id),
            ], 'qty')
            ->get();
        foreach ($products as $product) {
            if ($product->track_stock && $product->outlet_stock !== null) {
                $product->stock = (int) $product->outlet_stock;
            } elseif ($product->track_stock && $product->outlet_id !== null && (int) $product->outlet_id !== (int) $outlet->id) {
                $product->stock = 0;
            }
            foreach ($product->variants as $variant) {
                if ($variant->track_stock && $variant->outlet_stock !== null) {
                    $variant->stock = (int) $variant->outlet_stock;
                } elseif ($variant->track_stock && $product->outlet_id !== null && (int) $product->outlet_id !== (int) $outlet->id) {
                    $variant->stock = 0;
                }
            }
        }
        $staff = $request->attributes->get('posStaff');
        if ($staff instanceof PosStaff && $staff->businessRole() !== 'outlet_admin') {
            $products->each(fn (Product $product) => $product->makeHidden('cost_price'));
        }

        $categoryIds = $isInitial
            ? null
            : $products->pluck('category_id')->merge(
                Category::query()->where('tenant_id', $tenantId)
                    ->whereBetween('updated_at', [$querySince, $serverTime])
                    ->pluck('id')
            )->unique();
        $categories = Category::query()
            ->where('tenant_id', $tenantId)
            ->when($categoryIds !== null, fn ($query) => $query->whereIn('id', $categoryIds))
            ->get();

        $tablesEnabled = BusinessProfile::hasModule($organization, 'tables_selforder');
        $tables = $tablesEnabled
            ? DiningTable::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('outlet_id', $outlet->id)
                ->where('updated_at', '<=', $serverTime)
                ->when(!$isInitial, fn ($query) => $query->where('updated_at', '>=', $querySince))
                ->get()
            : collect();

        $members = PosMember::query()
            ->forOrganization((int) $organization->id)
            ->where('updated_at', '<=', $serverTime)
            ->when(!$isInitial, fn ($query) => $query->where('updated_at', '>=', $querySince))
            ->get()
            ->map(fn (PosMember $member) => [
                'id' => (int) $member->id,
                'name' => (string) $member->name,
                'phone' => $member->phone_normalized ?: $member->phone,
                'email' => $member->email,
                'total_points' => (int) $member->total_points,
                'total_orders' => (int) $member->total_orders,
                'total_spent' => (int) $member->total_spent,
                'redeemable_points' => (int) $member->redeemable_points,
            ]);
        $serialsEnabled = BusinessProfile::hasModule($organization, 'serial_imei');
        $serials = $serialsEnabled
            ? ProductSerial::query()
                ->where('tenant_id', $tenantId)
                ->where('outlet_id', $outlet->id)
                ->when($isInitial, fn ($query) => $query->where('status', 'available'))
                ->when(!$isInitial, fn ($query) => $query->whereBetween('updated_at', [$querySince, $serverTime]))
                ->get(['id', 'product_id', 'product_variant_id', 'serial_number', 'status'])
            : collect();

        $removed = ['products' => [], 'categories' => [], 'variants' => [], 'serials' => [], 'tables' => [], 'members' => []];
        if (!$isInitial) {
            $changes = PosSyncChange::query()
                ->where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$querySince, $serverTime])
                ->where(fn ($query) => $query->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))
                ->get(['entity_type', 'entity_id']);
            foreach ($changes as $change) {
                $key = match ($change->entity_type) {
                    'product' => 'products',
                    'category' => 'categories',
                    'variant' => 'variants',
                    'serial' => 'serials',
                    'table' => 'tables',
                    'member' => 'members',
                    default => null,
                };
                if ($key !== null) {
                    $removed[$key][] = (int) $change->entity_id;
                }
            }
            $removed['members'] = PosMember::query()
                ->where('organization_id', $organization->id)
                ->whereNotNull('merged_into_id')
                ->whereBetween('updated_at', [$querySince, $serverTime])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $this->success([
            'since' => $since?->toIso8601String(),
            'server_time' => $serverTime->toIso8601String(),
            'outlet_id' => (int) $outlet->id,
            'tables_enabled' => $tablesEnabled,
            'serials_enabled' => $serialsEnabled,
            'products' => $products->values(),
            'categories' => $categories->values(),
            'tables' => $tables->values(),
            'members' => $members->values(),
            'serials' => $serials->values(),
            'removed' => array_map(fn (array $ids) => array_values(array_unique($ids)), $removed),
        ], 'Perubahan data POS diterima');
    }

    private function authorizedOutlet(Request $request, int $outletId): ?Outlet
    {
        $organizationId = (int) ($request->attributes->get('currentOrganizationId') ?? $this->getOrg($request)?->id ?? 0);
        if ($organizationId <= 0) {
            return null;
        }
        $outlet = Outlet::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->find($outletId);
        if (!$outlet) {
            return null;
        }

        $staff = $request->attributes->get('posStaff');
        if ($staff instanceof PosStaff && !in_array($outletId, array_map('intval', $staff->assignedOutletIds()), true)) {
            return null;
        }

        return $outlet;
    }

    private function duplicateResult(Order $order, string $clientUuid): array
    {
        return [
            'client_uuid' => $clientUuid,
            'status' => 'duplicate',
            'order' => [
                'id' => (int) $order->id,
                'order_number' => (string) $order->order_number,
                'payment_status' => (string) $order->payment_status,
                'final_amount' => (int) $order->final_amount,
                'created_at_client' => $order->created_at_client?->toIso8601String(),
                'synced_at' => $order->created_at?->toIso8601String(),
            ],
        ];
    }

    private function negativeStockWarnings(Order $order, Outlet $outlet): array
    {
        $warnings = [];
        $items = $order->items()->with('variant')->get();
        foreach ($items->groupBy(fn ($item) => $item->product_id . ':' . ($item->product_variant_id ?? '')) as $rows) {
            $item = $rows->first();
            $product = Product::withoutGlobalScope('tenant')
                ->where('tenant_id', $outlet->tenant_slug)
                ->find($item->product_id);
            if (!$product || (!$product->track_stock && !$item->variant?->track_stock)) {
                continue;
            }
            $onHand = $this->stockMovements->onHand($product, (int) $outlet->id, $item->variant);
            if ($onHand < 0) {
                $warnings[] = [
                    'product_id' => (int) $product->id,
                    'variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
                    'name' => $item->variant?->name ?: $product->name,
                    'on_hand' => $onHand,
                    'code' => 'NEGATIVE_STOCK_REVIEW',
                ];
            }
        }

        return $warnings;
    }
}
