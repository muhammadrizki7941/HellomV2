<?php

namespace App\Http\Controllers\Api\V1\Hellom\Hpp;

use App\Models\Hpp\HppIngredient;
use App\Models\Hpp\HppProduct;
use App\Models\Hpp\HppSetting;
use App\Services\Hpp\CostEngine;
use App\Services\Hpp\ProductCosting;
use App\Support\Hpp\Templates;
use App\Support\Hpp\Units;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Overview, settings and first-time setup (business type + mode + starter template). */
class HppSetupController extends HppBaseController
{
    public function overview(Request $request): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $costing = new ProductCosting(CostEngine::for($org->id));
        $products = collect($costing->engine()->products())->filter(fn (HppProduct $p) => !$p->is_archived && !$p->is_subrecipe)
            ->map(fn (HppProduct $p) => $costing->summary($p))->values();

        return $this->ok([
            'settings' => $this->presentSettings($costing->engine()->settings),
            'counts' => [
                'products' => $products->count(),
                'ingredients' => HppIngredient::query()->where('organization_id', $org->id)->where('is_archived', false)->count(),
                'without_price' => $products->where('selling_price', 0)->count(),
                'red' => $products->where('food_cost_status', 'red')->count(),
                'below_target' => $products->filter(fn ($p) => $p['margin_pct'] !== null && $p['margin_pct'] < $p['target_margin_pct'])->count(),
            ],
            'products' => $products->sortBy(fn ($p) => $p['margin_pct'] ?? 999)->take(5)->values(),
            'options' => $this->options(),
        ], 'Kalkulator HPP');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $validated = $request->validate([
            'mode' => ['sometimes', Rule::in(HppSetting::MODES)],
            'business_type' => ['sometimes', Rule::in(HppSetting::BUSINESS_TYPES)],
            'target_margin_pct' => 'sometimes|numeric|min:0|max:90',
            'rounding' => ['sometimes', Rule::in(HppSetting::ROUNDINGS)],
            'price_method' => ['sometimes', Rule::in(HppSetting::PRICE_METHODS)],
            'labor_rate_per_hour' => 'sometimes|integer|min:0|max:10000000',
        ], [
            'target_margin_pct.max' => 'Target margin maksimal 90%.',
        ]);
        $settings = HppSetting::for($org->id);
        $settings->fill($validated)->save();

        return $this->ok(['settings' => $this->presentSettings($settings->fresh())], 'Pengaturan disimpan');
    }

    /** First visit: business type + mode; optionally load the starter ingredient list. */
    public function onboard(Request $request): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $validated = $request->validate([
            'business_type' => ['required', Rule::in(HppSetting::BUSINESS_TYPES)],
            'mode' => ['required', Rule::in(['quick', 'recipe'])],
            'use_template' => 'sometimes|boolean',
        ], ['business_type.required' => 'Pilih jenis usaha.']);

        DB::transaction(function () use ($org, $validated) {
            $settings = HppSetting::for($org->id);
            $settings->fill(['business_type' => $validated['business_type'], 'mode' => $validated['mode'], 'onboarded_at' => $settings->onboarded_at ?? now()])->save();
            $empty = !HppIngredient::query()->where('organization_id', $org->id)->exists();
            if (($validated['use_template'] ?? true) && $empty) {
                foreach (Templates::ingredients($validated['business_type']) as $row) {
                    HppIngredient::query()->create(['organization_id' => $org->id] + $row);
                }
            }
        });

        return $this->ok(['settings' => $this->presentSettings(HppSetting::for($org->id))], 'Siap dipakai');
    }

    /** @return array<string, mixed> */
    private function presentSettings(HppSetting $settings): array
    {
        return [
            'mode' => (string) $settings->mode,
            'business_type' => (string) $settings->business_type,
            'target_margin_pct' => (float) $settings->target_margin_pct,
            'rounding' => (string) $settings->rounding,
            'price_method' => (string) $settings->price_method,
            'labor_rate_per_hour' => (int) $settings->labor_rate_per_hour,
            'onboarded' => $settings->onboarded_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'business_types' => collect(Templates::BUSINESS_LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'use_units' => collect(Units::USE)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'buy_units' => collect(Units::BUY)->map(fn ($def, $key) => ['key' => $key, 'use_unit' => $def[0], 'factor' => $def[1]])->values(),
        ];
    }
}
