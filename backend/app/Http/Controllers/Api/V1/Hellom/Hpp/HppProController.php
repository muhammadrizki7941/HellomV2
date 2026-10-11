<?php

namespace App\Http\Controllers\Api\V1\Hellom\Hpp;

use App\Jobs\RecalculateHppCosts;
use App\Models\Hpp\HppIngredient;
use App\Models\Hpp\HppProduct;
use App\Models\Hpp\HppStockCount;
use App\Models\Organization;
use App\Models\Outlet;
use App\Services\Hpp\CostEngine;
use App\Services\Hpp\HppAccess;
use App\Services\Hpp\OutletPrices;
use App\Services\Hpp\ProductCosting;
use App\Services\Hpp\StockVariance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kalkulator HPP Pro (Tahap 4, berbayar — gratis untuk pelanggan POS Kasir): stok opname & selisih
 * teoritis vs aktual, simulasi "bagaimana jika", harga bahan per outlet, ekspor laporan.
 * FIFO is a price method in Pengaturan (also Pro-only).
 */
class HppProController extends HppBaseController
{
    public function stockCounts(Request $request, StockVariance $variance): JsonResponse
    {
        [$org, $denied] = $this->pro($request);
        if ($denied) {
            return $denied;
        }
        $outletId = (int) $request->query('outlet_id', 0);
        $counts = HppStockCount::query()->where('organization_id', $org->id)->where('outlet_id', $outletId)->orderByDesc('counted_at')->orderByDesc('id')->limit(50)->get();

        return $this->ok(['counts' => $counts->map(fn ($c) => $variance->presentCount($c))->values(), 'outlets' => $this->outlets($org)], 'Stok opname');
    }

    public function storeStockCount(Request $request, StockVariance $variance): JsonResponse
    {
        [$org, $denied] = $this->pro($request);
        if ($denied) {
            return $denied;
        }
        $validated = $request->validate([
            'outlet_id' => ['nullable', 'integer', Rule::exists('outlets', 'id')->where('organization_id', $org->id)],
            'notes' => 'nullable|string|max:255',
            'items' => 'required|array|min:1|max:300',
            'items.*.ingredient_id' => 'required|integer',
            'items.*.counted_qty' => 'required|numeric|min:0|max:100000000',
        ], ['items.required' => 'Isi hasil hitung minimal satu bahan.', 'items.*.counted_qty.required' => 'Isi jumlah hasil hitung.']);
        $count = $variance->count($org->id, (int) ($validated['outlet_id'] ?? 0), (int) $request->user()->id, $validated['items'], $validated['notes'] ?? null);

        return $this->ok(['count' => $variance->presentCount($count)], 'Stok opname disimpan', 201);
    }

    public function variance(Request $request, StockVariance $variance): JsonResponse
    {
        [$org, $denied] = $this->pro($request);
        if ($denied) {
            return $denied;
        }
        $validated = $request->validate(['outlet_id' => 'nullable|integer', 'from' => 'nullable|integer', 'to' => 'nullable|integer']);

        return $this->ok($variance->report($org->id, (int) ($validated['outlet_id'] ?? 0), $validated['from'] ?? null, $validated['to'] ?? null), 'Selisih stok');
    }

    /** "Bagaimana jika": ingredient price changes (%) and overhead change (%) → HPP & margin per product. */
    public function simulate(Request $request): JsonResponse
    {
        [$org, $denied] = $this->pro($request);
        if ($denied) {
            return $denied;
        }
        $validated = $request->validate([
            'ingredients' => 'nullable|array|max:200',
            'ingredients.*.id' => 'required|integer',
            'ingredients.*.pct' => 'required|numeric|min:-90|max:500',
            'overhead_pct' => 'nullable|numeric|min:-90|max:500',
            'outlet_id' => 'nullable|integer',
        ]);
        $outletId = isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null;
        $current = new ProductCosting(CostEngine::for($org->id, [], $outletId));
        $overrides = [];
        foreach ($validated['ingredients'] ?? [] as $change) {
            $ingredient = $current->engine()->ingredient((int) $change['id']);
            if ($ingredient) {
                $base = $outletId ? (OutletPrices::forOutlet($org->id, $outletId)[$ingredient->id] ?? (float) $ingredient->cost_per_unit) : (float) $ingredient->cost_per_unit;
                $overrides[(int) $ingredient->id] = $base * (1 + (float) $change['pct'] / 100);
            }
        }
        $simulated = new ProductCosting(CostEngine::for($org->id, $overrides, $outletId, 1 + (float) ($validated['overhead_pct'] ?? 0) / 100));

        $rows = collect($current->engine()->products())->filter(fn (HppProduct $p) => !$p->is_archived && !$p->is_subrecipe)
            ->map(function (HppProduct $p) use ($current, $simulated) {
                $now = $current->summary($p);
                $sim = $simulated->summary($p);

                return [
                    'id' => (int) $p->id, 'name' => $now['name'], 'selling_price' => $now['selling_price'], 'incomplete' => $now['incomplete'],
                    'cost_now' => $now['cost']['full'], 'cost_sim' => $sim['cost']['full'],
                    'margin_now' => $now['margin_pct'], 'margin_sim' => $sim['margin_pct'],
                    'suggested_now' => $now['suggested_price'], 'suggested_sim' => $sim['suggested_price'], 'target_margin_pct' => $now['target_margin_pct'],
                ];
            })->sortBy(fn ($r) => ($r['margin_sim'] ?? 999) - ($r['margin_now'] ?? 0))->values();

        return $this->ok(['products' => $rows], 'Simulasi');
    }

    public function outletPrices(Request $request): JsonResponse
    {
        [$org, $denied] = $this->pro($request);
        if ($denied) {
            return $denied;
        }
        $outlets = $this->outlets($org);
        $outletId = (int) $request->query('outlet_id', $outlets[0]['id'] ?? 0);
        $prices = $outletId ? OutletPrices::forOutlet($org->id, $outletId) : [];

        return $this->ok([
            'outlet_id' => $outletId ?: null,
            'outlets' => $outlets,
            'ingredients' => HppIngredient::query()->where('organization_id', $org->id)->where('is_archived', false)->orderBy('name')->get()
                ->map(fn (HppIngredient $i) => ['id' => (int) $i->id, 'name' => (string) $i->name, 'use_unit' => (string) $i->use_unit,
                    'cost_per_unit' => round((float) $i->cost_per_unit, 4), 'outlet_cost' => isset($prices[$i->id]) ? round($prices[$i->id], 4) : null])->values(),
        ], 'Harga per outlet');
    }

    public function updateOutletPrice(Request $request): JsonResponse
    {
        [$org, $denied] = $this->pro($request);
        if ($denied) {
            return $denied;
        }
        $validated = $request->validate([
            'outlet_id' => ['required', 'integer', Rule::exists('outlets', 'id')->where('organization_id', $org->id)],
            'ingredient_id' => ['required', 'integer', Rule::exists('hpp_ingredients', 'id')->where('organization_id', $org->id)],
            'cost_per_unit' => 'nullable|numeric|min:0|max:100000000',
        ]);
        $validated['cost_per_unit'] === null
            ? OutletPrices::clear((int) $validated['ingredient_id'], (int) $validated['outlet_id'])
            : OutletPrices::set((int) $validated['ingredient_id'], (int) $validated['outlet_id'], (float) $validated['cost_per_unit']);
        RecalculateHppCosts::dispatch($org->id);

        return $this->ok(['saved' => true], 'Harga outlet disimpan');
    }

    /** CSV (Excel-friendly, ; separated, UTF-8 BOM): products report or stock variance. */
    public function export(Request $request, StockVariance $variance): StreamedResponse|JsonResponse
    {
        [$org, $denied] = $this->pro($request);
        if ($denied) {
            return $denied;
        }
        $type = $request->query('type') === 'variance' ? 'variance' : 'products';
        $outletId = $request->filled('outlet_id') ? (int) $request->query('outlet_id') : null;
        if ($type === 'variance') {
            $report = $variance->report($org->id, (int) ($outletId ?? 0));
            $header = ['Bahan', 'Satuan', 'Stok awal', 'Pembelian', 'Stok akhir', 'Pemakaian teoritis', 'Pemakaian aktual', 'Selisih', 'Selisih %', 'Nilai selisih (Rp)'];
            $rows = array_map(fn ($r) => [$r['name'], $r['unit'], $r['opening'], $r['purchased'], $r['closing'], $r['theoretical'], $r['actual'], $r['variance'], $r['variance_pct'], round($r['value'])], $report['rows']);
        } else {
            $costing = new ProductCosting(CostEngine::for($org->id, [], $outletId));
            $header = ['Produk', 'HPP bahan', 'Kemasan', 'Tenaga kerja', 'Overhead', 'HPP penuh', 'Harga jual', 'Margin %', 'Food cost %', 'Saran harga', 'BEP/bulan'];
            $rows = collect($costing->engine()->products())->filter(fn (HppProduct $p) => !$p->is_archived && !$p->is_subrecipe)
                ->map(function (HppProduct $p) use ($costing) {
                    $s = $costing->summary($p);

                    return [$s['name'], round($s['cost']['material']), round($s['cost']['packaging']), round($s['cost']['labor']), round($s['cost']['overhead']),
                        round($s['cost']['full']), $s['selling_price'], $s['margin_pct'], $s['food_cost_pct'], $s['suggested_price'], $s['bep']['units_per_month'] ?? null];
                })->values()->all();
        }
        $filename = 'hpp-' . $type . '-' . now()->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_float($v) ? str_replace('.', ',', (string) $v) : $v, $row), ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: ?Organization, 1: ?JsonResponse} */
    private function pro(Request $request): array
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return [null, $denied];
        }
        if (!HppAccess::isPro($org->id)) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Fitur ini bagian dari Kalkulator HPP Pro. Gratis untuk pelanggan POS Kasir, atau berlangganan Pro.',
                'data' => HppAccess::pro($org->id),
                'error' => ['code' => 'HPP_PRO_REQUIRED', 'detail' => 'Kalkulator HPP Pro'],
            ], 403)];
        }

        return [$org, null];
    }

    /** @return list<array{id: int, name: string}> */
    private function outlets(Organization $org): array
    {
        return Outlet::query()->where('organization_id', $org->id)->orderBy('id')->get(['id', 'name'])
            ->map(fn ($o) => ['id' => (int) $o->id, 'name' => (string) $o->name])->values()->all();
    }
}
