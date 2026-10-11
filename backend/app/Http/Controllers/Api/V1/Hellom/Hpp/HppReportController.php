<?php

namespace App\Http\Controllers\Api\V1\Hellom\Hpp;

use App\Models\Hpp\HppProduct;
use App\Services\Hpp\CostEngine;
use App\Services\Hpp\ProductCosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Laporan: HPP & margin per product, most profitable vs most "makan modal", break-even point. */
class HppReportController extends HppBaseController
{
    public function products(Request $request): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $costing = new ProductCosting(CostEngine::for($org->id));
        $rows = collect($costing->engine()->products())
            ->filter(fn (HppProduct $p) => !$p->is_archived && !$p->is_subrecipe)
            ->map(function (HppProduct $p) use ($costing) {
                $s = $costing->summary($p);
                $fee = $s['main_channel'] ? $s['selling_price'] * $s['main_channel']['fee_pct'] / 100 : 0;

                return $s + ['profit_per_unit' => $s['selling_price'] > 0 && !$s['incomplete'] ? round($s['selling_price'] - $s['cost']['full'] - $fee, 2) : null];
            })->values();
        $priced = $rows->filter(fn ($r) => $r['selling_price'] > 0 && !$r['incomplete']);

        return $this->ok([
            'products' => $rows,
            'most_profitable' => $priced->sortByDesc('margin_pct')->take(5)->values(),
            'most_costly' => $priced->sortByDesc('food_cost_pct')->take(5)->values(),
            'overhead_total' => (int) round($costing->engine()->overheadTotal()),
            'settings' => [
                'overhead_method' => (string) $costing->engine()->settings->overhead_method,
                'monthly_units' => (int) $costing->engine()->settings->monthly_units,
            ],
        ], 'Laporan HPP');
    }
}
