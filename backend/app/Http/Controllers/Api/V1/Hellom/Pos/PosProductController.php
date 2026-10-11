<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\Product;
use App\Services\Pos\Audit\Snapshots;
use App\Support\Pos\ActivityActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Support\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PosProductController extends BasePosController
{
    public function index(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $products = Product::query()
            ->where('tenant_id', $tenantSlug)
            ->with(['category', 'options.values'])
            ->orderBy('category_id')
            ->orderBy('sort_order')
            ->get();

        return $this->success(['products' => $products], 'Products retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $validated = $request->validate($this->rules($tenantSlug), $this->messages(), $this->attributeNames());

        $imagePath = $request->hasFile('image') ? $this->storeImage($request->file('image')) : null;

        $product = Product::create([
            'tenant_id' => $tenantSlug,
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'slug' => \Illuminate\Support\Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'price' => (int) $validated['price'],
            'image_path' => $imagePath,
            'is_available' => $this->parseBool($request->input('is_available'), true),
            'track_stock' => $this->parseBool($request->input('track_stock'), false),
            'stock' => $this->parseBool($request->input('track_stock'), false)
                ? (int) ($request->input('stock') ?? 0)
                : null,
        ]);

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

        return $this->success(['product' => $product->load(['category', 'options.values'])], 'Product created');
    }

    public function update(Request $request, int $productId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $product = Product::where('tenant_id', $tenantSlug)->findOrFail($productId);

        $validated = $request->validate($this->rules($tenantSlug), $this->messages(), $this->attributeNames());

        $before = Snapshots::product($product);
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
            'track_stock' => $this->parseBool($request->input('track_stock'), false),
            'stock' => $this->parseBool($request->input('track_stock'), false)
                ? (int) ($request->input('stock') ?? 0)
                : null,
        ]);

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

        return $this->success(['product' => $product->load(['category', 'options.values'])], 'Product updated');
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
    private function rules(string $tenantSlug): array
    {
        return [
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
}
