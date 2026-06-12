<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PackageController extends Controller
{
    private function resolveProductOrFail(int|string $id): Product
    {
        $id = is_numeric($id) ? (int) $id : $id;

        $query = Product::query()->withoutGlobalScope('tenant')->whereKey($id)->where('is_package', true);

        return $query->firstOrFail();
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $packages = Product::query()
            ->where('is_package', true)
            ->with(['category', 'categories', 'packageItems.itemProduct'])
            ->orderBy('category_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $categories = Category::query()->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.packages.index', [
            'packages' => $packages,
            'categories' => $categories,
            'tenant' => null,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $categories = Category::query()->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.packages.create', [
            'categories' => $categories,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_available' => ['nullable', 'boolean'],

            'track_stock' => ['nullable', 'boolean'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000', 'required_if:track_stock,1'],

            'show_as_banner' => ['nullable', 'boolean'],
            'banner_title' => ['nullable', 'string', 'max:120'],
            'banner_subtitle' => ['nullable', 'string', 'max:160'],
            'banner_starts_at' => ['nullable', 'date'],
            'banner_ends_at' => ['nullable', 'date', 'after_or_equal:banner_starts_at'],

            'image' => ['nullable', 'image', 'max:2048'],
        ]);

        $categoryIds = array_values(array_unique(array_merge(
            [(int) $validated['category_id']],
            array_map('intval', $validated['category_ids'] ?? [])
        )));

        $slug = Str::slug($validated['name']);
        $baseSlug = $slug;
        $i = 2;
        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$i;
            $i++;
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product = Product::query()->create([
            'category_id' => (int) $validated['category_id'],
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'price' => (int) $validated['price'],
            'image_path' => $imagePath,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_available' => (bool) ($validated['is_available'] ?? true),

            'track_stock' => (bool) ($validated['track_stock'] ?? false),
            'stock' => ($validated['track_stock'] ?? false) ? (int) ($validated['stock'] ?? 0) : null,

            'is_package' => true,
            'show_as_banner' => (bool) ($validated['show_as_banner'] ?? false),
            'banner_title' => $validated['banner_title'] ?? null,
            'banner_subtitle' => $validated['banner_subtitle'] ?? null,
            'banner_starts_at' => isset($validated['banner_starts_at']) ? Carbon::parse($validated['banner_starts_at']) : null,
            'banner_ends_at' => isset($validated['banner_ends_at']) ? Carbon::parse($validated['banner_ends_at']) : null,
        ]);

        $product->categories()->sync($categoryIds);

        return redirect()->route('admin.packages.index')->with('success', 'Paket berhasil dibuat.');
    }

    public function show($product)
    {
        if (!$product instanceof Product) {
            $product = $this->resolveProductOrFail($product);
        }

        return redirect()->route('admin.packages.edit', $product);
    }

    public function edit($product)
    {
        if (!$product instanceof Product) {
            $product = $this->resolveProductOrFail($product);
        }
        $categories = Category::query()->orderBy('sort_order')->orderBy('name')->get();

        $packageCandidates = Product::query()
            ->where('id', '!=', $product->id)
            ->where('is_available', true)
            ->where('is_package', false)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('admin.packages.edit', [
            'product' => $product,
            'categories' => $categories,
            'packageCandidates' => $packageCandidates,
            'tenant' => null,
        ]);
    }

    public function update(Request $request, $product)
    {
        if (!$product instanceof Product) {
            $product = $this->resolveProductOrFail($product);
        }

        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_available' => ['nullable', 'boolean'],

            'track_stock' => ['nullable', 'boolean'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000', 'required_if:track_stock,1'],

            'show_as_banner' => ['nullable', 'boolean'],
            'banner_title' => ['nullable', 'string', 'max:120'],
            'banner_subtitle' => ['nullable', 'string', 'max:160'],
            'banner_starts_at' => ['nullable', 'date'],
            'banner_ends_at' => ['nullable', 'date', 'after_or_equal:banner_starts_at'],

            'image' => ['nullable', 'image', 'max:2048'],
            'remove_image' => ['nullable', 'boolean'],
        ]);

        $categoryIds = array_values(array_unique(array_merge(
            [(int) $validated['category_id']],
            array_map('intval', $validated['category_ids'] ?? [])
        )));

        $slug = Str::slug($validated['name']);
        $baseSlug = $slug;
        $i = 2;
        while (Product::query()->where('slug', $slug)->where('id', '!=', $product->id)->exists()) {
            $slug = $baseSlug.'-'.$i;
            $i++;
        }

        $imagePath = $product->image_path;
        if (($validated['remove_image'] ?? false) && $imagePath) {
            Storage::disk('public')->delete($imagePath);
            $imagePath = null;
        }
        if ($request->hasFile('image')) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product->update([
            'category_id' => (int) $validated['category_id'],
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'price' => (int) $validated['price'],
            'image_path' => $imagePath,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_available' => (bool) ($validated['is_available'] ?? false),

            'track_stock' => (bool) ($validated['track_stock'] ?? false),
            'stock' => ($validated['track_stock'] ?? false) ? (int) ($validated['stock'] ?? 0) : null,

            'is_package' => true,
            'show_as_banner' => (bool) ($validated['show_as_banner'] ?? false),
            'banner_title' => $validated['banner_title'] ?? null,
            'banner_subtitle' => $validated['banner_subtitle'] ?? null,
            'banner_starts_at' => isset($validated['banner_starts_at']) ? Carbon::parse($validated['banner_starts_at']) : null,
            'banner_ends_at' => isset($validated['banner_ends_at']) ? Carbon::parse($validated['banner_ends_at']) : null,
        ]);

        $product->categories()->sync($categoryIds);

        return redirect()->route('admin.packages.index')->with('success', 'Paket berhasil diupdate.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($product)
    {
        if (!$product instanceof Product) {
            $product = $this->resolveProductOrFail($product);
        }

        // Check if product is used in any reservation spaces
        if (DB::table('reservation_space_items')->where('product_id', $product->id)->exists()) {
            return back()->withErrors(['product' => 'Paket tidak dapat dihapus karena digunakan dalam ruang reservasi.']);
        }

        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product->categories()->detach();

        $product->delete();

        return redirect()->route('admin.packages.index')->with('success', 'Paket berhasil dihapus.');
    }
}