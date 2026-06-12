<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PackageItem;
use App\Models\Product;
use Illuminate\Http\Request;

class PackageItemController extends Controller
{
    public function store(Request $request, Product $product)
    {
        if (!$product->is_package) {
            abort(404);
        }

        $validated = $request->validate([
            'item_product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:99'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $itemProductId = (int) $validated['item_product_id'];
        if ($itemProductId === (int) $product->id) {
            return back()->withErrors(['item_product_id' => 'Produk paket tidak boleh berisi dirinya sendiri.']);
        }

        $row = PackageItem::query()->firstOrNew([
            'package_product_id' => $product->id,
            'item_product_id' => $itemProductId,
        ]);

        $row->qty = (int) $validated['qty'];
        $row->sort_order = (int) ($validated['sort_order'] ?? $row->sort_order ?? 0);
        $row->save();

        return redirect()->route('admin.products.edit', $product);
    }

    public function destroy(Product $product, PackageItem $packageItem)
    {
        if ((int) $packageItem->package_product_id !== (int) $product->id) {
            abort(404);
        }

        $packageItem->delete();

        return redirect()->route('admin.products.edit', $product);
    }
}
