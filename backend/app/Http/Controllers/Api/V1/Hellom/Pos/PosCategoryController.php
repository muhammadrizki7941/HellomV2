<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosCategoryController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $categories = Category::query()
            ->where('tenant_id', $tenantSlug)
            ->orderBy('sort_order')
            ->get();

        return $this->success(['categories' => $categories], 'Categories retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $slug = \Illuminate\Support\Str::slug($validated['name']);
        $baseSlug = $slug;
        $counter = 1;
        while (Category::where('slug', $slug)->where('tenant_id', $tenantSlug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        $category = Category::create([
            'tenant_id' => $tenantSlug,
            'name' => $validated['name'],
            'slug' => $slug,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->success(['category' => $category], 'Category created');
    }

    public function update(Request $request, int $categoryId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $category = Category::where('tenant_id', $tenantSlug)->findOrFail($categoryId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $category->update([
            'name' => $validated['name'],
            'slug' => \Illuminate\Support\Str::slug($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->success(['category' => $category], 'Category updated');
    }

    public function destroy(Request $request, int $categoryId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $category = Category::where('tenant_id', $tenantSlug)->findOrFail($categoryId);
        $category->delete();

        return $this->success(null, 'Category deleted');
    }
}