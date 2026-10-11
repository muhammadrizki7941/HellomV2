<?php

namespace App\Services\Hpp;

use App\Models\Hpp\HppProduct;
use App\Models\Product;

/**
 * What the HPP screens show for a product: cost per unit split into bahan / kemasan / tenaga kerja /
 * lain-lain, the selling price (live from POS when linked), margin, food cost %, and the suggested
 * price for the target margin.
 */
final class ProductCosting
{
    /** @var array<int, int> POS product id => price */
    private array $posPrices = [];

    public function __construct(private readonly CostEngine $engine)
    {
        $ids = array_values(array_filter(array_map(fn (HppProduct $p) => $p->pos_product_id, $engine->products())));
        if ($ids !== []) {
            $this->posPrices = Product::withoutGlobalScope('tenant')->whereIn('id', $ids)->pluck('price', 'id')
                ->map(fn ($price) => (int) round((float) $price))->all();
        }
    }

    public function engine(): CostEngine
    {
        return $this->engine;
    }

    public function price(HppProduct $product): int
    {
        if ($product->pos_product_id && isset($this->posPrices[(int) $product->pos_product_id])) {
            return $this->posPrices[(int) $product->pos_product_id];
        }

        return (int) $product->selling_price;
    }

    public function marginTarget(HppProduct $product): float
    {
        return (float) ($product->target_margin_pct ?? $this->engine->settings->target_margin_pct);
    }

    /** @return array<string, mixed> */
    public function summary(HppProduct $product): array
    {
        $unit = $this->engine->unitCost((int) $product->id);
        $full = $unit['direct'];
        $price = $this->price($product);
        $target = $this->marginTarget($product);
        $rounding = (string) $this->engine->settings->rounding;
        $foodCost = PriceAdvisor::foodCostPct($price, $unit['material'] + $unit['packaging']);

        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'mode' => (string) $product->mode,
            'is_subrecipe' => (bool) $product->is_subrecipe,
            'pos_product_id' => $product->pos_product_id ? (int) $product->pos_product_id : null,
            'yield_qty' => (float) $product->yield_qty,
            'yield_unit' => (string) $product->yield_unit,
            'selling_price' => $price,
            'target_margin_pct' => $target,
            'cost' => [
                'material' => round($unit['material'], 2),
                'packaging' => round($unit['packaging'], 2),
                'labor' => round($unit['labor'], 2),
                'other' => round($unit['other'], 2),
                'direct' => round($unit['direct'], 2),
                'overhead' => 0.0,
                'full' => round($full, 2),
            ],
            'margin_pct' => PriceAdvisor::margin($price, $full),
            'food_cost_pct' => $foodCost,
            'food_cost_status' => PriceAdvisor::foodCostStatus($foodCost),
            'suggested_price' => PriceAdvisor::suggest($full, $target, 0, 0, $rounding),
            'is_archived' => (bool) $product->is_archived,
        ];
    }
}
