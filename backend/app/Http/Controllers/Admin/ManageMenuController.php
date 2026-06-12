<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ManageMenuController extends Controller
{
    public function index(Request $request)
    {
        // Get tenant from route
        $tenantSlug = $request->route('tenant');
        $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->firstOrFail();

        $categories = Category::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $products = Product::query()
            ->with('categories:id,name')
            ->where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('cashier.manage_menu', [
            'categories' => $categories,
            'products' => $products,
            'tenant' => $tenant,
        ]);
    }

    // Categories API Methods
    public function getCategories(Request $request)
    {
        $tenantSlug = $request->route('tenant');
        $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->firstOrFail();

        $categories = Category::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

    public function storeCategory(Request $request)
    {
        $tenantSlug = $request->route('tenant');
        $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json($category, 201);
    }

    public function updateCategory(Request $request, Category $category)
    {
        $tenantSlug = $request->route('tenant');
        $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->firstOrFail();

        // Ensure category belongs to tenant
        if ($category->tenant_id !== $tenant->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $category->update($validated);

        return response()->json($category);
    }

    public function deleteCategory(Request $request, Category $category)
    {
        $tenantSlug = $request->route('tenant');
        $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->firstOrFail();

        // Ensure category belongs to tenant
        if ($category->tenant_id !== $tenant->id) {
            abort(404);
        }

        // Check if category has products
        if ($category->products()->count() > 0) {
            return response()->json(['error' => 'Cannot delete category with products'], 400);
        }

        $category->delete();

        return response()->json(['success' => true]);
    }

    // Products API Methods
    public function getProducts(Request $request)
    {
        $tenantSlug = $request->route('tenant');
        $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->firstOrFail();

        $products = Product::query()
            ->with('categories:id,name')
            ->where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json($products);
    }

    public function storeProduct(Request $request)
    {
        $tenantSlug = $request->route('tenant');
        $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'max:2048'], // 2MB max
            'is_available' => ['boolean'],
            'track_stock' => ['boolean'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['integer', 'min:0'],
            'category_ids' => ['array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'image' => $imagePath,
            'is_available' => $validated['is_available'] ?? true,
            'track_stock' => $validated['track_stock'] ?? false,
            'stock' => $validated['stock'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        if (!empty($validated['category_ids'])) {
            $product->categories()->attach($validated['category_ids']);
        }

        $product->load('categories:id,name');

        return response()->json($product, 201);
    }

    public function updateProduct(Request $request, Product $product)
    {
        $tenantSlug = $request->route('tenant');
        $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->firstOrFail();

        // Ensure product belongs to tenant
        if ($product->tenant_id !== $tenant->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'max:2048'], // 2MB max
            'is_available' => ['boolean'],
            'track_stock' => ['boolean'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['integer', 'min:0'],
            'category_ids' => ['array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
        ]);

        $imagePath = $product->image;
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($product->image && Storage::disk('public')->exists($product->image)) {
                Storage::disk('public')->delete($product->image);
            }
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'image' => $imagePath,
            'is_available' => $validated['is_available'] ?? true,
            'track_stock' => $validated['track_stock'] ?? false,
            'stock' => $validated['stock'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        $product->categories()->sync($validated['category_ids'] ?? []);

        $product->load('categories:id,name');

        return response()->json($product);
    }

    public function deleteProduct(Request $request, Product $product)
    {
        $tenantSlug = $request->route('tenant');
        $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->firstOrFail();

        // Ensure product belongs to tenant
        if ($product->tenant_id !== $tenant->id) {
            abort(404);
        }

        // Delete image if exists
        if ($product->image && Storage::disk('public')->exists($product->image)) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        return response()->json(['success' => true]);
    }
}