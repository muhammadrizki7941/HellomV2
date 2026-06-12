<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PosProductController extends BaseApiController
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

        try {
            $validated = $request->validate([
            'category_id' => [
                'required',
                function ($attribute, $value, $fail) use ($tenantSlug) {
                    $exists = \App\Models\Category::withoutGlobalScope('tenant')
                        ->where('id', $value)
                        ->where('tenant_id', $tenantSlug)
                        ->exists();
                    if (!$exists) {
                        $fail('Category not found for this tenant.');
                    }
                }
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'is_available' => 'nullable|in:true,false,1,0',
            'track_stock' => 'nullable|in:true,false,1,0',
            'stock' => 'nullable|integer|min:0|required_if:track_stock,1',
            'image' => 'nullable|image|max:2048',
            'options' => 'nullable|array',
            'options.*.name' => 'required_with:options|string|max:100',
            'options.*.type' => 'required_with:options|string|in:single,multi',
            'options.*.is_required' => 'nullable|in:true,false,1,0',
            'options.*.values' => 'required_with:options|array|min:1',
            'options.*.values.*.name' => 'required|string|max:100',
            'options.*.values.*.price_delta' => 'nullable|numeric|min:0',
        ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
                'debug_received' => $request->all(),
            ], 422);
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product = Product::create([
            'tenant_id' => $tenantSlug,
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'slug' => \Illuminate\Support\Str::slug($validated['name']),
            'description' => $validated['description'],
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

        return $this->success(['product' => $product->load(['category', 'options.values'])], 'Product created');
    }

    public function update(Request $request, int $productId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $product = Product::where('tenant_id', $tenantSlug)->findOrFail($productId);

        $validated = $request->validate([
            'category_id' => [
                'required',
                function ($attribute, $value, $fail) use ($tenantSlug) {
                    $exists = \App\Models\Category::withoutGlobalScope('tenant')
                        ->where('id', $value)
                        ->where('tenant_id', $tenantSlug)
                        ->exists();
                    if (!$exists) {
                        $fail('Category not found for this tenant.');
                    }
                }
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'is_available' => 'nullable|in:true,false,1,0',
            'track_stock' => 'nullable|in:true,false,1,0',
            'stock' => 'nullable|integer|min:0|required_if:track_stock,1',
            'image' => 'nullable|image|max:2048',
            'options' => 'nullable|array',
            'options.*.name' => 'required_with:options|string|max:100',
            'options.*.type' => 'required_with:options|string|in:single,multi',
            'options.*.is_required' => 'nullable|in:true,false,1,0',
            'options.*.values' => 'required_with:options|array|min:1',
            'options.*.values.*.name' => 'required|string|max:100',
            'options.*.values.*.price_delta' => 'nullable|numeric|min:0',
        ]);

        $imagePath = $product->image_path;
        if ($request->hasFile('image')) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product->update([
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'slug' => \Illuminate\Support\Str::slug($validated['name']),
            'description' => $validated['description'],
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
            Storage::disk('public')->delete($product->image_path);
        }

        $product->delete();

        return $this->success(null, 'Product deleted');
    }

    private function parseBool(mixed $value, bool $default = false): bool
    {
        return filter_var($value ?? $default, FILTER_VALIDATE_BOOLEAN);
    }
}