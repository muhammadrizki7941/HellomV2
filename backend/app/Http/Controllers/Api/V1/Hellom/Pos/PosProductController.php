<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\Product;
use App\Models\PosStaff;
use App\Services\Pos\Audit\Snapshots;
use App\Support\Pos\ActivityActions;
use App\Services\Pos\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PosProductController extends BasePosController
{
    public function __construct(private readonly StockMovementService $stockMovements)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $products = Product::query()
            ->where('tenant_id', $tenantSlug)
            ->with([
                'category',
                'options.values',
                'variants' => fn ($query) => $query->withSum([
                    'stockMovements as outlet_stock' => fn ($movements) => $movements->where('outlet_id', $this->posOutlet($request)?->id),
                ], 'qty'),
                'priceTiers',
            ])
            ->withSum([
                'stockMovements as outlet_stock' => fn ($query) => $query->where('outlet_id', $this->posOutlet($request)?->id),
            ], 'qty')
            ->orderBy('category_id')
            ->orderBy('sort_order')
            ->get();
        foreach ($products as $product) {
            if ($product->track_stock && $product->outlet_stock !== null) {
                $product->stock = (int) $product->outlet_stock;
            } elseif ($product->track_stock && $this->posOutlet($request)?->id !== null
                && $product->outlet_id !== null
                && (int) $product->outlet_id !== (int) $this->posOutlet($request)->id) {
                $product->stock = 0;
            }
            foreach ($product->variants as $variant) {
                if ($variant->track_stock && $variant->outlet_stock !== null) {
                    $variant->stock = (int) $variant->outlet_stock;
                } elseif ($variant->track_stock && $this->posOutlet($request)?->id !== null
                    && $product->outlet_id !== null
                    && (int) $product->outlet_id !== (int) $this->posOutlet($request)->id) {
                    $variant->stock = 0;
                }
            }
        }
        $staff = $request->attributes->get('posStaff');
        if ($staff instanceof PosStaff && $staff->businessRole() !== 'outlet_admin') {
            $products->makeHidden('cost_price');
        }

        return $this->success(['products' => $products], 'Products retrieved');
    }

    public function importTemplate(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $columns = [
            'category_id', 'name', 'sku', 'barcode', 'unit', 'price', 'cost_price',
            'track_stock', 'stock', 'min_stock', 'has_variants', 'track_serial',
            'warranty_days', 'description', 'is_available',
        ];
        if ($this->isCashier($request)) {
            $columns = array_values(array_filter($columns, fn (string $column): bool => $column !== 'cost_price'));
        }

        return response()->streamDownload(function () use ($columns): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $columns);
            fclose($output);
        }, 'template-produk-hellom.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request): JsonResponse
    {
        $tenantSlug = (string) $request->attributes->get('posTenantSlug');
        $validated = $request->validate(['file' => 'required|file|mimes:csv,txt|max:10240']);
        $path = $validated['file']->getRealPath();
        $handle = $path ? fopen($path, 'r') : false;
        if ($handle === false) {
            return $this->error('File CSV tidak dapat dibaca.', 'CSV_READ_FAILED', null, 422);
        }

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);

            return $this->error('File CSV kosong atau tidak memiliki header.', 'CSV_HEADER_MISSING', null, 422);
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(fn ($value) => trim((string) $value), $header);
        if ($this->isCashier($request) && in_array('cost_price', $header, true)) {
            fclose($handle);

            return $this->error('Kasir tidak memiliki akses untuk mengimpor harga beli.', 'COST_PRICE_FORBIDDEN', null, 403);
        }
        if (count($header) !== count(array_unique($header)) || !in_array('name', $header, true) || !in_array('price', $header, true) || !in_array('category_id', $header, true)) {
            fclose($handle);

            return $this->error('Header wajib berisi category_id, name, dan price tanpa nama kolom ganda.', 'CSV_HEADER_INVALID', null, 422);
        }

        $created = 0;
        $errors = [];
        $rowNumber = 1;
        try {
            while (($values = fgetcsv($handle)) !== false) {
                $rowNumber++;
                if ($rowNumber > 1001) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'Maksimal 1.000 baris per impor.'];
                    break;
                }
                if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                $values = array_pad(array_slice($values, 0, count($header)), count($header), '');
                $row = array_combine($header, $values);
                $row = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), $row);
                $row['track_stock'] = $this->parseBool($row['track_stock'] ?? false);
                $row['has_variants'] = $this->parseBool($row['has_variants'] ?? false);
                $row['track_serial'] = $this->parseBool($row['track_serial'] ?? false);
                $row['is_available'] = $this->parseBool($row['is_available'] ?? true, true);

                $validator = Validator::make($row, [
                    'category_id' => ['required', 'integer', function ($attribute, $value, $fail) use ($tenantSlug): void {
                        if (!\App\Models\Category::withoutGlobalScope('tenant')->where('tenant_id', $tenantSlug)->whereKey($value)->exists()) {
                            $fail('Kategori tidak ditemukan di usaha ini.');
                        }
                    }],
                    'name' => 'required|string|max:255',
                    'sku' => [
                        'nullable',
                        'string',
                        'max:100',
                        Rule::unique('products', 'sku')->where('tenant_id', $tenantSlug),
                        function ($attribute, $value, $fail) use ($tenantSlug): void {
                            if ($value !== null && DB::table('product_variants')->where('tenant_id', $tenantSlug)->where('sku', $value)->exists()) {
                                $fail('SKU sudah digunakan oleh varian lain.');
                            }
                        },
                    ],
                    'barcode' => ['nullable', 'string', 'max:100', function ($attribute, $value, $fail) use ($request): void {
                        if ($value && !$this->moduleEnabled('barcode', (int) $request->attributes->get('currentOrganizationId'))) {
                            $fail('Aktifkan modul barcode terlebih dahulu.');
                        }
                    }],
                    'unit' => 'nullable|string|max:32',
                    'price' => 'required|integer|min:0|max:100000000',
                    'cost_price' => 'nullable|integer|min:0|max:100000000',
                    'stock' => 'nullable|integer|min:0|max:1000000',
                    'min_stock' => 'nullable|integer|min:0|max:1000000',
                    'warranty_days' => 'nullable|integer|min:0|max:65535',
                    'description' => 'nullable|string|max:2000',
                ]);
                if ($validator->fails()) {
                    $errors[] = ['row' => $rowNumber, 'message' => $validator->errors()->first()];
                    continue;
                }

                try {
                    DB::transaction(function () use ($row, $tenantSlug, $request): void {
                        $trackStock = (bool) $row['track_stock'];
                        $product = Product::create([
                            'tenant_id' => $tenantSlug,
                            'category_id' => (int) $row['category_id'],
                            'name' => $row['name'],
                            'slug' => \Illuminate\Support\Str::slug($row['name']),
                            'description' => $row['description'] ?: null,
                            'price' => (int) $row['price'],
                            'is_available' => (bool) $row['is_available'],
                            'track_stock' => $trackStock,
                            'stock' => $trackStock ? 0 : null,
                            'sku' => $row['sku'] ?: null,
                            'barcode' => $row['barcode'] ?: null,
                            'unit' => $row['unit'] ?: 'pcs',
                            'cost_price' => ($row['cost_price'] ?? null) !== '' ? ($row['cost_price'] ?? null) : null,
                            'min_stock' => (int) ($row['min_stock'] ?: 0),
                            'has_variants' => (bool) $row['has_variants'],
                            'track_serial' => (bool) $row['track_serial'],
                            'warranty_days' => $row['warranty_days'] !== '' ? $row['warranty_days'] : null,
                        ]);
                        $stock = (int) ($row['stock'] ?: 0);
                        if ($trackStock && $stock > 0) {
                            $this->stockMovements->setOnHand(
                                $product,
                                $stock,
                                $this->posOutlet($request)?->id,
                                $request->user()?->id,
                                'Saldo awal dari impor CSV'
                            );
                        }
                    });
                    $created++;
                } catch (\Illuminate\Database\QueryException $exception) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'SKU/barcode duplikat atau data baris tidak dapat disimpan.'];
                }
            }
        } finally {
            fclose($handle);
        }

        return $this->success([
            'created_count' => $created,
            'error_count' => count($errors),
            'errors' => $errors,
        ], 'Impor produk selesai');
    }

    public function store(Request $request): JsonResponse
    {
        if ($this->isCashier($request) && $request->exists('cost_price')) {
            return $this->error('Kasir tidak memiliki akses untuk mengatur harga beli.', 'COST_PRICE_FORBIDDEN', null, 403);
        }

        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $validated = $request->validate($this->rules($tenantSlug, null, (int) $request->attributes->get('currentOrganizationId')), $this->messages(), $this->attributeNames());

        $imagePath = $request->hasFile('image') ? $this->storeImage($request->file('image')) : null;
        $trackStock = $this->parseBool($request->input('track_stock'), false);
        $initialStock = $trackStock ? (int) ($validated['stock'] ?? 0) : 0;

        $product = Product::create([
            'tenant_id' => $tenantSlug,
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'slug' => \Illuminate\Support\Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'price' => (int) $validated['price'],
            'image_path' => $imagePath,
            'is_available' => $this->parseBool($request->input('is_available'), true),
            'track_stock' => $trackStock,
            'stock' => $trackStock ? 0 : null,
            'sku' => $validated['sku'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'unit' => $validated['unit'] ?? 'pcs',
            'cost_price' => $validated['cost_price'] ?? null,
            'min_stock' => (int) ($validated['min_stock'] ?? 0),
            'has_variants' => $this->parseBool($request->input('has_variants'), false),
            'track_serial' => $this->parseBool($request->input('track_serial'), false),
            'warranty_days' => $validated['warranty_days'] ?? null,
        ]);

        if ($initialStock > 0) {
            $this->stockMovements->setOnHand(
                $product,
                $initialStock,
                $this->posOutlet($request)?->id,
                $request->user()?->id,
                'Saldo awal saat produk dibuat'
            );
        }

        // Create product options
        if (isset($validated['options']) && is_array($validated['options'])) {
            foreach ($validated['options'] as $optionData) {
                // Skip options without name or values
                if (empty($optionData['name']) || !isset($optionData['values']) || !is_array($optionData['values'])) {
                    continue;
                }

                // Filter out empty values
                $validValues = array_filter($optionData['values'], function($value) {
                    return !empty($value['name']);
                });

                if (empty($validValues)) {
                    continue;
                }

                $option = $product->options()->create([
                    'name' => $optionData['name'],
                    'type' => $optionData['type'] ?: 'single',
                    'is_required' => $this->parseBool($optionData['is_required'] ?? false),
                    'is_active' => true,
                    'sort_order' => 0,
                ]);

                foreach ($validValues as $valueData) {
                    $option->values()->create([
                        'name' => $valueData['name'],
                        'price_delta' => (int) ($valueData['price_delta'] ?? 0),
                        'is_active' => true,
                        'sort_order' => 0,
                    ]);
                }
            }
        }

        $this->recordActivity($request, ActivityActions::PRODUCT_CREATED, ['target_type' => 'product', 'target_id' => $product->id, 'target_label' => $product->name,
            'after' => Snapshots::product($product)]);

        $product = $product->load(['category', 'options.values']);
        $this->hideCostPriceFromCashier($request, $product);

        return $this->success(['product' => $product], 'Product created');
    }

    public function update(Request $request, int $productId): JsonResponse
    {
        if ($this->isCashier($request) && $request->exists('cost_price')) {
            return $this->error('Kasir tidak memiliki akses untuk mengatur harga beli.', 'COST_PRICE_FORBIDDEN', null, 403);
        }

        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $product = Product::where('tenant_id', $tenantSlug)->findOrFail($productId);

        $validated = $request->validate($this->rules($tenantSlug, $product->id, (int) $request->attributes->get('currentOrganizationId')), $this->messages(), $this->attributeNames());

        $before = Snapshots::product($product);
        $wasTrackingStock = (bool) $product->track_stock;
        $outletId = $this->posOutlet($request)?->id;
        $previousStock = $this->stockMovements->onHand($product, $outletId);
        $trackStock = $this->parseBool($request->input('track_stock'), $wasTrackingStock);
        $targetStock = array_key_exists('stock', $validated) ? (int) $validated['stock'] : $previousStock;
        $imagePath = $product->image_path;
        if ($request->hasFile('image')) {
            $newPath = $this->storeImage($request->file('image'));
            if ($imagePath) {
                ImageOptimizer::delete($imagePath);
            }
            $imagePath = $newPath;
        }

        $product->update([
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'slug' => \Illuminate\Support\Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'price' => (int) $validated['price'],
            'image_path' => $imagePath,
            'is_available' => $this->parseBool($request->input('is_available'), true),
            'track_stock' => $trackStock,
            'sku' => array_key_exists('sku', $validated) ? $validated['sku'] : $product->sku,
            'barcode' => array_key_exists('barcode', $validated) ? $validated['barcode'] : $product->barcode,
            'unit' => $validated['unit'] ?? $product->unit,
            'cost_price' => array_key_exists('cost_price', $validated) ? $validated['cost_price'] : $product->cost_price,
            'min_stock' => $validated['min_stock'] ?? $product->min_stock,
            'has_variants' => $this->parseBool($request->input('has_variants'), (bool) $product->has_variants),
            'track_serial' => $this->parseBool($request->input('track_serial'), (bool) $product->track_serial),
            'warranty_days' => array_key_exists('warranty_days', $validated) ? $validated['warranty_days'] : $product->warranty_days,
        ]);

        if ($trackStock) {
            if (!$wasTrackingStock) {
                $product->forceFill(['stock' => 0])->save();
            }
            $this->stockMovements->setOnHand(
                $product,
                $targetStock,
                $outletId,
                $request->user()?->id,
                'Penyesuaian stok dari form produk'
            );
        }

        // Update product options - delete existing and create new ones
        $product->options()->each(function ($option) {
            $option->values()->delete();
        });
        $product->options()->delete();

        if (isset($validated['options']) && is_array($validated['options'])) {
            foreach ($validated['options'] as $optionData) {
                // Skip options without name or values
                if (empty($optionData['name']) || !isset($optionData['values']) || !is_array($optionData['values'])) {
                    continue;
                }

                // Filter out empty values
                $validValues = array_filter($optionData['values'], function($value) {
                    return !empty($value['name']);
                });

                if (empty($validValues)) {
                    continue;
                }

                $option = $product->options()->create([
                    'name' => $optionData['name'],
                    'type' => $optionData['type'] ?: 'single',
                    'is_required' => $this->parseBool($optionData['is_required'] ?? false),
                    'is_active' => true,
                    'sort_order' => 0,
                ]);

                foreach ($validValues as $valueData) {
                    $option->values()->create([
                        'name' => $valueData['name'],
                        'price_delta' => (int) ($valueData['price_delta'] ?? 0),
                        'is_active' => true,
                        'sort_order' => 0,
                    ]);
                }
            }
        }

        $after = Snapshots::product($product->fresh());
        if ($before !== $after) {
            $this->recordActivity($request, ActivityActions::PRODUCT_UPDATED, ['target_type' => 'product', 'target_id' => $product->id, 'target_label' => $product->name,
                'before' => $before, 'after' => $after, 'severity' => $before['price'] !== $after['price'] || $before['stock'] !== $after['stock'] ? 'warning' : 'info']);
        }

        $product = $product->fresh()->load(['category', 'options.values', 'variants', 'priceTiers']);
        $this->hideCostPriceFromCashier($request, $product);

        return $this->success(['product' => $product], 'Product updated');
    }

    private function hideCostPriceFromCashier(Request $request, Product $product): void
    {
        if ($this->isCashier($request)) {
            $product->makeHidden('cost_price');
        }
    }

    private function isCashier(Request $request): bool
    {
        $staff = $request->attributes->get('posStaff');

        return $staff instanceof PosStaff && $staff->businessRole() === 'cashier';
    }

    public function destroy(Request $request, int $productId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $product = Product::where('tenant_id', $tenantSlug)->findOrFail($productId);

        if ($product->image_path) {
            ImageOptimizer::delete($product->image_path);
        }

        $before = Snapshots::product($product);
        $product->delete();
        $this->recordActivity($request, ActivityActions::PRODUCT_DELETED, ['target_type' => 'product', 'target_id' => $productId, 'target_label' => $before['name'], 'before' => $before]);

        return $this->success(null, 'Product deleted');
    }

    private function parseBool(mixed $value, bool $default = false): bool
    {
        return filter_var($value ?? $default, FILTER_VALIDATE_BOOLEAN);
    }

    /** Rules shared by create and update (multipart FormData from the POS menu page). */
    private function rules(string $tenantSlug, ?int $ignoreProductId = null, ?int $organizationId = null): array
    {
        return [
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->where('tenant_id', $tenantSlug)->ignore($ignoreProductId),
                function ($attribute, $value, $fail) use ($tenantSlug): void {
                    if ($value !== null && DB::table('product_variants')->where('tenant_id', $tenantSlug)->where('sku', $value)->exists()) {
                        $fail('SKU sudah digunakan oleh varian lain.');
                    }
                },
            ],
            'barcode' => ['nullable', 'string', 'max:100', function ($attribute, $value, $fail) use ($organizationId): void {
                if ($value && !$this->moduleEnabled('barcode', $organizationId)) {
                    $fail('Aktifkan modul barcode terlebih dahulu.');
                }
            }],
            'unit' => 'nullable|string|max:32',
            'cost_price' => 'nullable|integer|min:0|max:100000000',
            'min_stock' => 'nullable|integer|min:0|max:1000000',
            'has_variants' => ['nullable', 'in:true,false,1,0', function ($attribute, $value, $fail) use ($organizationId): void {
                if ($this->parseBool($value) && !$this->moduleEnabled('variants', $organizationId)) {
                    $fail('Aktifkan modul varian terlebih dahulu.');
                }
            }],
            'track_serial' => ['nullable', 'in:true,false,1,0', function ($attribute, $value, $fail) use ($organizationId): void {
                if ($this->parseBool($value) && !$this->moduleEnabled('serial_imei', $organizationId)) {
                    $fail('Aktifkan modul IMEI/serial terlebih dahulu.');
                }
            }],
            'warranty_days' => ['nullable', 'integer', 'min:0', 'max:65535', function ($attribute, $value, $fail) use ($organizationId): void {
                if ($value !== null && !$this->moduleEnabled('warranty', $organizationId)) {
                    $fail('Aktifkan modul garansi terlebih dahulu.');
                }
            }],
            'category_id' => [
                'required',
                function ($attribute, $value, $fail) use ($tenantSlug) {
                    $exists = \App\Models\Category::withoutGlobalScope('tenant')
                        ->where('id', $value)
                        ->where('tenant_id', $tenantSlug)
                        ->exists();
                    if (!$exists) {
                        $fail('Kategori tidak ditemukan. Pilih kategori yang ada.');
                    }
                },
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|min:0|max:100000000',
            'is_available' => 'nullable|in:true,false,1,0',
            'track_stock' => 'nullable|in:true,false,1,0',
            'stock' => 'nullable|integer|min:0|max:1000000',
            // Phone photos are big: up to 8 MB, stored as a compressed WebP (max 1600 px).
            'image' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
            'options' => 'nullable|array|max:20',
            'options.*.name' => 'required_with:options|string|max:100',
            'options.*.type' => 'required_with:options|string|in:single,multi',
            'options.*.is_required' => 'nullable|in:true,false,1,0',
            'options.*.values' => 'required_with:options|array|min:1|max:30',
            'options.*.values.*.name' => 'required|string|max:100',
            'options.*.values.*.price_delta' => 'nullable|numeric|min:0|max:100000000',
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'category_id.required' => 'Pilih kategori produk dulu.',
            'name.required' => 'Nama produk wajib diisi.',
            'name.max' => 'Nama produk maksimal 255 karakter.',
            'price.required' => 'Harga wajib diisi.',
            'price.numeric' => 'Harga harus berupa angka.',
            'price.min' => 'Harga tidak boleh minus.',
            'stock.integer' => 'Stok harus angka bulat.',
            'stock.min' => 'Stok tidak boleh minus.',
            'image.mimes' => 'Foto harus JPG, PNG, atau WebP.',
            'image.max' => 'Ukuran foto maksimal 8 MB.',
            'image.uploaded' => 'Foto gagal diunggah. Coba foto lain atau yang lebih kecil (maks. 8 MB).',
            'options.*.name.required_with' => 'Nama opsi wajib diisi.',
            'options.*.values.required_with' => 'Setiap opsi butuh minimal satu pilihan.',
            'options.*.values.*.name.required' => 'Nama pilihan wajib diisi.',
        ];
    }

    /** @return array<string, string> */
    private function attributeNames(): array
    {
        return ['category_id' => 'kategori', 'name' => 'nama produk', 'price' => 'harga', 'stock' => 'stok', 'image' => 'foto', 'description' => 'deskripsi'];
    }

    private function storeImage(UploadedFile $file): string
    {
        try {
            return ImageOptimizer::storeWebp($file, 'products');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }
    }

    private function moduleEnabled(string $module, ?int $organizationId = null): bool
    {
        $organizationId ??= auth()->user()?->current_organization_id;
        $organization = $organizationId ? \App\Models\Organization::query()->find($organizationId) : null;

        return $organization !== null && \App\Support\Pos\BusinessProfile::hasModule($organization, $module);
    }
}
