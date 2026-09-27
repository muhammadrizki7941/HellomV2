<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ReservationSpace;
use App\Models\ReservationSpaceItem;
use Illuminate\Http\Request;

class ReservationSpaceItemController extends Controller
{
    public function store(Request $request, ReservationSpace $space)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:1000'],
            'unit_price' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $product = Product::query()->findOrFail((int) $validated['product_id']);

        $unitPrice = isset($validated['unit_price']) ? (int) $validated['unit_price'] : (int) $product->price;

        ReservationSpaceItem::query()->create([
            'reservation_space_id' => $space->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => $unitPrice,
            'qty' => (int) $validated['qty'],
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return back();
    }

    public function update(Request $request, ReservationSpace $space, ReservationSpaceItem $item)
    {
        if ((int) $item->reservation_space_id !== (int) $space->id) {
            abort(404);
        }

        $validated = $request->validate([
            'qty' => ['required', 'integer', 'min:1', 'max:1000'],
            'unit_price' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $item->update([
            'qty' => (int) $validated['qty'],
            'unit_price' => (int) $validated['unit_price'],
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return back();
    }

    public function destroy(ReservationSpace $space, ReservationSpaceItem $item)
    {
        if ((int) $item->reservation_space_id !== (int) $space->id) {
            abort(404);
        }

        $item->delete();

        return back();
    }
}
