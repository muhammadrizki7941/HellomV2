<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use Illuminate\Http\Request;

class ProductOptionValueController extends Controller
{
    public function create(Product $product, ProductOption $option)
    {
        abort_unless($option->product_id === $product->id, 404);

        return view('admin.products.options.values.create', [
            'product' => $product,
            'option' => $option,
        ]);
    }

    public function store(Request $request, Product $product, ProductOption $option)
    {
        abort_unless($option->product_id === $product->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'price_delta' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $option->values()->create([
            'name' => $validated['name'],
            'price_delta' => (int) ($validated['price_delta'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return redirect()->route('admin.products.options.index', $product);
    }

    public function edit(Product $product, ProductOption $option, ProductOptionValue $value)
    {
        abort_unless($option->product_id === $product->id, 404);
        abort_unless($value->product_option_id === $option->id, 404);

        return view('admin.products.options.values.edit', [
            'product' => $product,
            'option' => $option,
            'value' => $value,
        ]);
    }

    public function update(Request $request, Product $product, ProductOption $option, ProductOptionValue $value)
    {
        abort_unless($option->product_id === $product->id, 404);
        abort_unless($value->product_option_id === $option->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'price_delta' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $value->update([
            'name' => $validated['name'],
            'price_delta' => (int) ($validated['price_delta'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return redirect()->route('admin.products.options.index', $product);
    }

    public function destroy(Product $product, ProductOption $option, ProductOptionValue $value)
    {
        abort_unless($option->product_id === $product->id, 404);
        abort_unless($value->product_option_id === $option->id, 404);

        $value->delete();

        return redirect()->route('admin.products.options.index', $product);
    }
}
