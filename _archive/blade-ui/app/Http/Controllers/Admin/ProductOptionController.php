<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductOption;
use Illuminate\Http\Request;

class ProductOptionController extends Controller
{
    public function index(Product $product)
    {
        $product->load(['options' => function ($q) {
            $q->orderBy('sort_order')->orderBy('name')->with(['values' => function ($q2) {
                $q2->orderBy('sort_order')->orderBy('name');
            }]);
        }]);

        return view('admin.products.options.index', [
            'product' => $product,
        ]);
    }

    public function create(Product $product)
    {
        return view('admin.products.options.create', [
            'product' => $product,
        ]);
    }

    public function store(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', 'in:single,multi'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $product->options()->create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'is_required' => (bool) ($validated['is_required'] ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return redirect()->route('admin.products.options.index', $product);
    }

    public function edit(Product $product, ProductOption $option)
    {
        abort_unless($option->product_id === $product->id, 404);

        return view('admin.products.options.edit', [
            'product' => $product,
            'option' => $option,
        ]);
    }

    public function update(Request $request, Product $product, ProductOption $option)
    {
        abort_unless($option->product_id === $product->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', 'in:single,multi'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $option->update([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'is_required' => (bool) ($validated['is_required'] ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return redirect()->route('admin.products.options.index', $product);
    }

    public function destroy(Product $product, ProductOption $option)
    {
        abort_unless($option->product_id === $product->id, 404);
        $option->delete();

        return redirect()->route('admin.products.options.index', $product);
    }
}
