<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductVariant;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Services\Pos\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PosInventoryController extends BasePosController
{
    public function __construct(private readonly StockMovementService $movements)
    {
    }

    public function variants(Request $request, int $productId): JsonResponse
    {
        $product = $this->product($request, $productId);
        $outletId = $this->posOutlet($request)?->id;
        $variants = $product->variants()
            ->withSum(['stockMovements as outlet_stock' => fn ($query) => $query->where('outlet_id', $outletId)], 'qty')
            ->orderBy('id')
            ->get();
        foreach ($variants as $variant) {
            if ($variant->track_stock && $variant->outlet_stock !== null) {
                $variant->stock = (int) $variant->outlet_stock;
            }
        }

        return $this->success(['variants' => $variants], 'Varian produk berhasil dimuat');
    }

    public function availableSerials(Request $request, int $productId): JsonResponse
    {
        $product = $this->product($request, $productId);
        $data = $request->validate([
            'product_variant_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
        ]);
        if (!$product->track_serial) {
            return $this->error('Produk ini tidak menggunakan serial/IMEI.', 'SERIAL_NOT_REQUIRED', null, 422);
        }

        $variantId = $data['product_variant_id'] ?? null;
        if ($variantId && !$product->variants()->whereKey($variantId)->exists()) {
            return $this->error('Varian tidak sesuai dengan produk.', 'VARIANT_NOT_FOUND', null, 422);
        }
        $serials = ProductSerial::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('outlet_id', $this->posOutlet($request)?->id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variantId)
            ->where('status', 'available')
            ->when($data['search'] ?? null, fn ($query, $search) => $query->where('serial_number', 'like', '%' . $search . '%'))
            ->orderBy('serial_number')
            ->limit(100)
            ->get(['id', 'serial_number', 'product_variant_id']);

        return $this->success(['serials' => $serials], 'Serial tersedia berhasil dimuat');
    }

    public function priceTiers(Request $request, int $productId): JsonResponse
    {
        $product = $this->product($request, $productId);

        return $this->success([
            'tiers' => $product->priceTiers()->with('variant:id,name')->orderBy('min_quantity')->get(),
        ], 'Harga bertingkat berhasil dimuat');
    }

    public function storeVariant(Request $request, int $productId): JsonResponse
    {
        $product = $this->product($request, $productId);
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'attributes' => 'nullable|array',
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('product_variants', 'sku')->where('tenant_id', $product->tenant_id),
                function ($attribute, $value, $fail) use ($product): void {
                    if ($value !== null && DB::table('products')->where('tenant_id', $product->tenant_id)->where('sku', $value)->exists()) {
                        $fail('SKU sudah digunakan oleh produk lain.');
                    }
                },
            ],
            'barcode' => 'nullable|string|max:100',
            'price' => 'nullable|integer|min:0|max:100000000',
            'track_stock' => 'required|boolean',
            'stock' => 'nullable|integer|min:0|max:1000000',
            'is_active' => 'nullable|boolean',
        ]);
        $variant = DB::transaction(function () use ($product, $data, $request) {
            $variant = $product->variants()->create([
                ...$data,
                'tenant_id' => $product->tenant_id,
                'stock' => !empty($data['track_stock']) ? 0 : null,
            ]);
            if (!empty($data['track_stock']) && (int) ($data['stock'] ?? 0) > 0) {
                $this->movements->setOnHand(
                    $product,
                    (int) $data['stock'],
                    $this->posOutlet($request)?->id,
                    $request->user()?->id,
                    'Saldo awal varian',
                    $variant
                );
            }

            return $variant->fresh();
        });

        return $this->success(['variant' => $variant], 'Varian produk berhasil ditambahkan', 201);
    }

    public function updateVariant(Request $request, int $productId, int $variantId): JsonResponse
    {
        $product = $this->product($request, $productId);
        $variant = $product->variants()->findOrFail($variantId);
        $wasTrackingStock = (bool) $variant->track_stock;
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'attributes' => 'nullable|array',
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('product_variants', 'sku')->where('tenant_id', $product->tenant_id)->ignore($variantId),
                function ($attribute, $value, $fail) use ($product): void {
                    if ($value !== null && DB::table('products')->where('tenant_id', $product->tenant_id)->where('sku', $value)->exists()) {
                        $fail('SKU sudah digunakan oleh produk lain.');
                    }
                },
            ],
            'barcode' => 'nullable|string|max:100',
            'price' => 'nullable|integer|min:0|max:100000000',
            'track_stock' => 'sometimes|required|boolean',
            'stock' => 'nullable|integer|min:0|max:1000000',
            'is_active' => 'nullable|boolean',
        ]);
        DB::transaction(function () use ($request, $product, $variant, $data, $wasTrackingStock) {
            $variant->update(collect($data)->except('stock')->all());
            if (($data['track_stock'] ?? $wasTrackingStock) && array_key_exists('stock', $data)) {
                if (!$wasTrackingStock) {
                    $variant->forceFill(['stock' => 0])->save();
                }
                $this->movements->setOnHand(
                    $product,
                    (int) $data['stock'],
                    $this->posOutlet($request)?->id,
                    $request->user()?->id,
                    'Penyesuaian stok varian',
                    $variant
                );
            }
        });

        return $this->success(['variant' => $variant->fresh()], 'Varian produk berhasil diperbarui');
    }

    public function destroyVariant(Request $request, int $productId, int $variantId): JsonResponse
    {
        $variant = $this->product($request, $productId)->variants()->findOrFail($variantId);
        if ($variant->stockMovements()->exists() || $variant->serials()->exists() || $variant->priceTiers()->exists()) {
            return $this->error('Varian memiliki riwayat stok atau transaksi dan tidak dapat dihapus.', 'VARIANT_HAS_HISTORY', null, 409);
        }
        $variant->delete();

        return $this->success(null, 'Varian produk berhasil dihapus');
    }

    public function updatePriceTiers(Request $request, int $productId): JsonResponse
    {
        $product = $this->product($request, $productId);
        $data = $request->validate([
            'tiers' => 'required|array|min:1|max:20',
            'tiers.*.label' => 'required|string|max:80',
            'tiers.*.min_quantity' => 'required|integer|min:1|max:1000000',
            'tiers.*.customer_type' => 'nullable|string|max:80',
            'tiers.*.price' => 'required|integer|min:0|max:100000000',
            'tiers.*.product_variant_id' => 'nullable|integer',
        ]);
        foreach ($data['tiers'] as $tier) {
            if (!empty($tier['product_variant_id']) && !$product->variants()->whereKey($tier['product_variant_id'])->exists()) {
                return $this->error('Varian harga tidak sesuai dengan produk.', 'VARIANT_NOT_FOUND', null, 422);
            }
        }

        $tiers = DB::transaction(function () use ($product, $data) {
            $product->priceTiers()->delete();
            foreach ($data['tiers'] as $tier) {
                $product->priceTiers()->create(['tenant_id' => $product->tenant_id, ...$tier]);
            }

            return $product->priceTiers()->orderBy('min_quantity')->get();
        });

        return $this->success(['tiers' => $tiers], 'Harga bertingkat berhasil diperbarui');
    }

    public function movements(Request $request): JsonResponse
    {
        $tenantSlug = (string) $request->attributes->get('posTenantSlug');
        $outlet = $this->posOutlet($request);
        $data = $request->validate([
            'product_id' => 'nullable|integer',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $movements = StockMovement::query()
            ->where('tenant_id', $tenantSlug)
            ->when($outlet, fn ($query) => $query->where('outlet_id', $outlet->id))
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->with(['product:id,name,sku', 'variant:id,name', 'outlet:id,name'])
            ->latest('id')
            ->paginate($data['per_page'] ?? 50);

        return $this->success(['movements' => $movements], 'Riwayat pergerakan stok berhasil dimuat');
    }

    public function suppliers(Request $request): JsonResponse
    {
        $tenantSlug = (string) $request->attributes->get('posTenantSlug');
        $suppliers = DB::table('pos_suppliers')
            ->where('tenant_id', $tenantSlug)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        return $this->success(['suppliers' => $suppliers], 'Supplier berhasil dimuat');
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $tenantSlug = (string) $request->attributes->get('posTenantSlug');
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:160',
            'address' => 'nullable|string|max:5000',
            'notes' => 'nullable|string|max:5000',
        ]);
        $id = DB::table('pos_suppliers')->insertGetId([
            ...$data,
            'tenant_id' => $tenantSlug,
            'outlet_id' => $this->posOutlet($request)?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->success(['supplier' => DB::table('pos_suppliers')->find($id)], 'Supplier berhasil ditambahkan', 201);
    }

    public function purchase(Request $request): JsonResponse
    {
        $tenantSlug = (string) $request->attributes->get('posTenantSlug');
        $outlet = $this->posOutlet($request);
        $data = $request->validate([
            'supplier_id' => 'nullable|integer',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1|max:500',
            'items.*.product_id' => 'required|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.qty' => 'required|integer|min:1|max:1000000',
            'items.*.unit_cost' => 'required|integer|min:0|max:100000000',
            'items.*.serial_numbers' => 'nullable|array|max:1000',
            'items.*.serial_numbers.*' => 'required|string|max:100',
        ]);

        $purchase = DB::transaction(function () use ($data, $tenantSlug, $outlet, $request) {
            if (!empty($data['supplier_id']) && !DB::table('pos_suppliers')
                ->where('id', $data['supplier_id'])->where('tenant_id', $tenantSlug)->whereNull('deleted_at')->exists()) {
                abort(422, 'Supplier tidak ditemukan.');
            }
            $total = array_sum(array_map(fn ($item) => (int) $item['qty'] * (int) $item['unit_cost'], $data['items']));
            $purchaseId = DB::table('pos_purchases')->insertGetId([
                'tenant_id' => $tenantSlug,
                'outlet_id' => $outlet?->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'total_cost' => $total,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
                'purchased_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $seenSerials = [];
            foreach ($data['items'] as $itemData) {
                $product = Product::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantSlug)
                    ->lockForUpdate()
                    ->findOrFail($itemData['product_id']);
                $variant = null;
                if (!empty($itemData['product_variant_id'])) {
                    $variant = $product->variants()->lockForUpdate()->findOrFail($itemData['product_variant_id']);
                }
                $serials = array_values($itemData['serial_numbers'] ?? []);
                if ($product->track_serial && (count($serials) !== (int) $itemData['qty'] || count(array_unique($serials)) !== count($serials))) {
                    abort(422, "Isi IMEI/serial unik sebanyak jumlah barang untuk {$product->name}.");
                }
                if ($variant && $product->track_serial && !$variant->track_stock) {
                    abort(422, "Aktifkan pelacakan stok untuk varian {$variant->name} sebelum mencatat serial.");
                }
                if (!$product->track_serial && $serials !== []) {
                    abort(422, "Produk {$product->name} tidak menggunakan IMEI/serial.");
                }

                foreach ($serials as $serialNumber) {
                    if (isset($seenSerials[$serialNumber]) || ProductSerial::query()
                        ->where('tenant_id', $tenantSlug)
                        ->where('serial_number', $serialNumber)
                        ->exists()) {
                        abort(422, "IMEI/serial {$serialNumber} sudah terdaftar.");
                    }
                    $seenSerials[$serialNumber] = true;
                }

                $purchaseItemId = DB::table('pos_purchase_items')->insertGetId([
                    'purchase_id' => $purchaseId,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'qty' => $itemData['qty'],
                    'unit_cost' => $itemData['unit_cost'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->movements->record(
                    $product,
                    (int) $itemData['qty'],
                    'purchase',
                    $outlet?->id,
                    $variant,
                    'purchase_item',
                    $purchaseItemId,
                    'Barang masuk dari pembelian',
                    $request->user()?->id
                );
                $product->update(['cost_price' => $itemData['unit_cost']]);
                foreach ($serials as $serialNumber) {
                    ProductSerial::query()->create([
                        'tenant_id' => $tenantSlug,
                        'outlet_id' => $outlet?->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'serial_number' => $serialNumber,
                        'status' => 'available',
                    ]);
                }
            }

            return DB::table('pos_purchases')->find($purchaseId);
        });

        return $this->success(['purchase' => $purchase], 'Barang masuk berhasil dicatat', 201);
    }

    public function stocktake(Request $request): JsonResponse
    {
        $tenantSlug = (string) $request->attributes->get('posTenantSlug');
        $outlet = $this->posOutlet($request);
        $data = $request->validate([
            'notes' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1|max:1000',
            'items.*.product_id' => 'required|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.counted_qty' => 'required|integer|min:0|max:1000000',
            'items.*.note' => 'nullable|string|max:1000',
        ]);
        $stocktake = DB::transaction(function () use ($data, $tenantSlug, $outlet, $request) {
            $stocktakeId = DB::table('pos_stocktakes')->insertGetId([
                'tenant_id' => $tenantSlug,
                'outlet_id' => $outlet?->id,
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($data['items'] as $item) {
                $product = Product::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantSlug)
                    ->lockForUpdate()
                    ->findOrFail($item['product_id']);
                $variant = !empty($item['product_variant_id'])
                    ? $product->variants()->lockForUpdate()->findOrFail($item['product_variant_id'])
                    : null;
                $systemQty = $this->movements->onHand($product, $outlet?->id, $variant);
                $countedQty = (int) $item['counted_qty'];
                if ($product->track_serial) {
                    $availableSerialCount = ProductSerial::query()
                        ->where('tenant_id', $tenantSlug)
                        ->where('outlet_id', $outlet?->id)
                        ->where('product_id', $product->id)
                        ->where('product_variant_id', $variant?->id)
                        ->where('status', 'available')
                        ->count();
                    if ($countedQty !== $availableSerialCount) {
                        abort(422, "Jumlah fisik {$product->name} harus sama dengan jumlah IMEI/serial tersedia ({$availableSerialCount}).");
                    }
                }
                DB::table('pos_stocktake_items')->insert([
                    'stocktake_id' => $stocktakeId,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'system_qty' => $systemQty,
                    'counted_qty' => $countedQty,
                    'difference' => $countedQty - $systemQty,
                    'note' => $item['note'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->movements->setOnHand(
                    $product,
                    $countedQty,
                    $outlet?->id,
                    $request->user()?->id,
                    $item['note'] ?? $data['notes'] ?? 'Penyesuaian hasil stok opname',
                    $variant
                );
            }

            return DB::table('pos_stocktakes')->find($stocktakeId);
        });

        return $this->success(['stocktake' => $stocktake], 'Stok opname berhasil disimpan', 201);
    }

    public function transfer(Request $request): JsonResponse
    {
        $sourceOutlet = $this->posOutlet($request);
        $tenantSlug = (string) $request->attributes->get('posTenantSlug');
        $data = $request->validate([
            'destination_outlet_id' => 'required|integer',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:5000',
            'client_uuid' => 'nullable|uuid',
            'created_at_client' => 'nullable|date',
            'items' => 'required|array|min:1|max:500',
            'items.*.product_id' => 'required|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.qty' => 'required|integer|min:1|max:1000000',
            'items.*.serial_numbers' => 'nullable|array|max:1000',
            'items.*.serial_numbers.*' => 'required|string|max:100',
        ]);
        if (!$sourceOutlet) {
            return $this->error('Outlet asal tidak ditemukan dari token aktif.', 'CONTEXT_MISSING');
        }
        $destinationOutlet = Outlet::query()
            ->where('organization_id', $sourceOutlet->organization_id)
            ->where('is_active', true)
            ->findOrFail($data['destination_outlet_id']);
        if ((int) $destinationOutlet->id === (int) $sourceOutlet->id) {
            return $this->error('Pilih outlet tujuan yang berbeda.', 'TRANSFER_OUTLET_INVALID', null, 422);
        }
        $staff = $request->attributes->get('posStaff');
        if ($staff instanceof \App\Models\PosStaff
            && !in_array((int) $destinationOutlet->id, $staff->assignedOutletIds(), true)) {
            return $this->error(
                'Akun ini tidak ditugaskan ke outlet tujuan.',
                'POS_OUTLET_NOT_ASSIGNED',
                null,
                403
            );
        }

        $transfer = DB::transaction(function () use ($data, $tenantSlug, $sourceOutlet, $destinationOutlet, $request) {
            if (!empty($data['client_uuid'])) {
                $existing = DB::table('pos_stock_transfers')
                    ->where('tenant_id', $tenantSlug)
                    ->where('client_uuid', $data['client_uuid'])
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $transferId = DB::table('pos_stock_transfers')->insertGetId([
                'tenant_id' => $tenantSlug,
                'source_outlet_id' => $sourceOutlet->id,
                'destination_outlet_id' => $destinationOutlet->id,
                'reference_number' => $data['reference_number'] ?? null,
                'client_uuid' => $data['client_uuid'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $usedSerials = [];

            foreach ($data['items'] as $itemData) {
                $sourceProduct = Product::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantSlug)
                    ->lockForUpdate()
                    ->findOrFail($itemData['product_id']);
                if (!$sourceProduct->sku) {
                    abort(422, "Tambahkan SKU ke {$sourceProduct->name} sebelum transfer antar outlet.");
                }
                $destinationTenant = (string) ($destinationOutlet->tenant_slug ?: $tenantSlug);
                $destinationProduct = Product::withoutGlobalScope('tenant')
                    ->where('tenant_id', $destinationTenant)
                    ->where('sku', $sourceProduct->sku)
                    ->lockForUpdate()
                    ->first();
                if (!$destinationProduct) {
                    abort(422, "SKU {$sourceProduct->sku} tidak tersedia di outlet tujuan.");
                }

                $sourceVariant = null;
                $destinationVariant = null;
                if (!empty($itemData['product_variant_id'])) {
                    $sourceVariant = $sourceProduct->variants()->lockForUpdate()->findOrFail($itemData['product_variant_id']);
                    if (!$sourceVariant->sku) {
                        abort(422, "Tambahkan SKU ke varian {$sourceVariant->name} sebelum transfer.");
                    }
                    $destinationVariant = $destinationProduct->variants()
                        ->where('sku', $sourceVariant->sku)
                        ->lockForUpdate()
                        ->first();
                    if (!$destinationVariant) {
                        abort(422, "Varian SKU {$sourceVariant->sku} tidak tersedia di outlet tujuan.");
                    }
                }

                $quantity = (int) $itemData['qty'];
                if ($this->movements->onHand($sourceProduct, $sourceOutlet->id, $sourceVariant) < $quantity) {
                    abort(409, "Stok {$sourceProduct->name} tidak mencukupi di outlet asal.");
                }
                $serials = array_values($itemData['serial_numbers'] ?? []);
                if ($sourceProduct->track_serial && (count($serials) !== $quantity || count(array_unique($serials)) !== $quantity)) {
                    abort(422, "Pilih {$quantity} IMEI/serial unik untuk {$sourceProduct->name}.");
                }
                if ($sourceProduct->track_serial !== $destinationProduct->track_serial) {
                    abort(422, "Pengaturan IMEI/serial produk tujuan tidak cocok untuk {$sourceProduct->name}.");
                }
                if (!$sourceProduct->track_serial && $serials !== []) {
                    abort(422, "Produk {$sourceProduct->name} tidak menggunakan IMEI/serial.");
                }
                foreach ($serials as $serialNumber) {
                    if (isset($usedSerials[$serialNumber])) {
                        abort(422, 'IMEI/serial yang sama dipilih lebih dari sekali.');
                    }
                    $usedSerials[$serialNumber] = true;
                }

                $transferItemId = DB::table('pos_stock_transfer_items')->insertGetId([
                    'transfer_id' => $transferId,
                    'source_product_id' => $sourceProduct->id,
                    'destination_product_id' => $destinationProduct->id,
                    'source_variant_id' => $sourceVariant?->id,
                    'destination_variant_id' => $destinationVariant?->id,
                    'qty' => $quantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->movements->record(
                    $sourceProduct,
                    -$quantity,
                    'transfer',
                    $sourceOutlet->id,
                    $sourceVariant,
                    'stock_transfer_out',
                    $transferId,
                    'Transfer stok keluar ke ' . $destinationOutlet->name,
                    $request->user()?->id,
                    $this->transferMovementUuid($transferItemId, 'out'),
                    $data['created_at_client'] ?? null
                );
                $this->movements->record(
                    $destinationProduct,
                    $quantity,
                    'transfer',
                    $destinationOutlet->id,
                    $destinationVariant,
                    'stock_transfer_in',
                    $transferId,
                    'Transfer stok masuk dari ' . $sourceOutlet->name,
                    $request->user()?->id,
                    $this->transferMovementUuid($transferItemId, 'in'),
                    $data['created_at_client'] ?? null
                );

                if ($serials !== []) {
                    $serialRows = ProductSerial::query()
                        ->where('tenant_id', $tenantSlug)
                        ->where('outlet_id', $sourceOutlet->id)
                        ->where('product_id', $sourceProduct->id)
                        ->where('product_variant_id', $sourceVariant?->id)
                        ->where('status', 'available')
                        ->whereIn('serial_number', $serials)
                        ->lockForUpdate()
                        ->get();
                    if ($serialRows->count() !== $quantity) {
                        abort(409, 'Satu atau lebih IMEI/serial tidak tersedia di outlet asal.');
                    }
                    if (ProductSerial::query()
                        ->where('tenant_id', $destinationTenant)
                        ->whereIn('serial_number', $serials)
                        ->whereNotIn('id', $serialRows->pluck('id'))
                        ->exists()) {
                        abort(409, 'IMEI/serial sudah tercatat di outlet tujuan.');
                    }
                    foreach ($serialRows as $serial) {
                        $serial->update([
                            'tenant_id' => $destinationTenant,
                            'outlet_id' => $destinationOutlet->id,
                            'product_id' => $destinationProduct->id,
                            'product_variant_id' => $destinationVariant?->id,
                        ]);
                    }
                }
            }

            return DB::table('pos_stock_transfers')->find($transferId);
        });

        return $this->success(['transfer' => $transfer], 'Transfer stok berhasil dicatat', 201);
    }

    private function transferMovementUuid(int $transferItemId, string $direction): string
    {
        $hash = hash('sha256', "stock-transfer-item:{$transferItemId}:{$direction}");

        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
    }

    public function warrantyLookup(Request $request): JsonResponse
    {
        $tenantSlug = (string) $request->attributes->get('posTenantSlug');
        $data = $request->validate([
            'serial_number' => 'nullable|string|max:100|required_without_all:order_number,customer_phone',
            'order_number' => 'nullable|string|max:100|required_without_all:serial_number,customer_phone',
            'customer_phone' => 'nullable|string|max:30|required_without_all:serial_number,order_number',
        ]);
        $items = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $tenantSlug)
            ->when($data['serial_number'] ?? null, fn ($query, $serial) => $query->where('order_items.serial_number', $serial))
            ->when($data['order_number'] ?? null, fn ($query, $number) => $query->where('orders.order_number', $number))
            ->when($data['customer_phone'] ?? null, fn ($query, $phone) => $query->where('orders.customer_phone', $phone))
            ->select(['orders.order_number', 'orders.customer_name', 'orders.customer_phone', 'orders.created_at', 'order_items.product_name', 'order_items.serial_number', 'order_items.warranty_expires_at'])
            ->latest('orders.created_at')
            ->limit(100)
            ->get();

        return $this->success(['warranties' => $items], 'Data garansi berhasil dicari');
    }

    private function product(Request $request, int $productId): Product
    {
        return Product::withoutGlobalScope('tenant')
            ->where('tenant_id', $request->attributes->get('posTenantSlug'))
            ->findOrFail($productId);
    }
}
