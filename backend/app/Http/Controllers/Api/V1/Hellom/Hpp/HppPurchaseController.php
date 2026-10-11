<?php

namespace App\Http\Controllers\Api\V1\Hellom\Hpp;

use App\Models\Hpp\HppIngredient;
use App\Models\Hpp\HppPurchase;
use App\Models\Outlet;
use App\Services\Hpp\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Belanja bahan: records purchases; ingredient prices and stock update from them. */
class HppPurchaseController extends HppBaseController
{
    public function index(Request $request): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $names = HppIngredient::query()->where('organization_id', $org->id)->pluck('name', 'id');
        $units = HppIngredient::query()->where('organization_id', $org->id)->pluck('buy_unit', 'id');
        $purchases = HppPurchase::query()->where('organization_id', $org->id)->with('items')
            ->orderByDesc('purchased_at')->orderByDesc('id')->limit(100)->get();
        $outlets = Outlet::query()->where('organization_id', $org->id)->orderBy('id')->pluck('name', 'id');

        return $this->ok([
            'purchases' => $purchases->map(fn (HppPurchase $p) => [
                'id' => (int) $p->id, 'purchased_at' => $p->purchased_at->toDateString(), 'supplier' => $p->supplier, 'total' => (int) $p->total,
                'outlet' => $p->outlet_id ? ['id' => (int) $p->outlet_id, 'name' => $outlets[$p->outlet_id] ?? null] : null, 'notes' => $p->notes,
                'items' => $p->items->map(fn ($i) => [
                    'ingredient_id' => (int) $i->ingredient_id, 'name' => $names[$i->ingredient_id] ?? '(bahan dihapus)', 'unit' => $units[$i->ingredient_id] ?? '',
                    'qty' => (float) $i->qty, 'total_price' => (int) $i->total_price, 'unit_cost' => (float) $i->unit_cost,
                    'change_pct' => (float) $i->previous_cost > 0 ? round(((float) $i->unit_cost - (float) $i->previous_cost) / (float) $i->previous_cost * 100, 1) : null,
                ])->values(),
            ])->values(),
            'outlets' => $outlets->map(fn ($name, $id) => ['id' => (int) $id, 'name' => $name])->values(),
        ], 'Pembelian');
    }

    public function store(Request $request, PurchaseService $service): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $validated = $request->validate([
            'purchased_at' => 'required|date|before_or_equal:today',
            'supplier' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:255',
            'outlet_id' => ['nullable', 'integer', Rule::exists('outlets', 'id')->where('organization_id', $org->id)],
            'items' => 'required|array|min:1|max:100',
            'items.*.ingredient_id' => 'required|integer',
            'items.*.qty' => 'required|numeric|min:0.001|max:10000000',
            'items.*.total_price' => 'required|integer|min:0|max:10000000000',
        ], [
            'items.required' => 'Tambahkan minimal satu bahan yang dibeli.',
            'items.*.qty.required' => 'Isi jumlah yang dibeli.',
            'items.*.total_price.required' => 'Isi total harganya.',
            'purchased_at.before_or_equal' => 'Tanggal belanja tidak boleh di masa depan.',
        ]);
        $result = $service->record($org->id, (int) $request->user()->id, $validated);

        return $this->ok(['purchase_id' => (int) $result['purchase']->id, 'changes' => $result['changes']], 'Belanja dicatat', 201);
    }
}
