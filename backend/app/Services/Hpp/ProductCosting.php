<?php

namespace App\Services\Hpp;

use App\Models\Hpp\HppChannel;
use App\Models\Hpp\HppProduct;
use App\Models\Product;

/**
 * What the HPP screens show for a product: cost per unit (bahan / kemasan / tenaga kerja /
 * lain-lain / overhead), the selling price (live from POS when linked), margin after the main
 * channel's fee, food cost %, the suggested price per channel and the break-even point:
 *
 *   BEP (porsi/bulan) = biaya tetap per bulan ÷ (harga − biaya variabel − fee channel)
 */
final class ProductCosting
{
    /** Channels whose default price is the shop price; ojol channels need their own price. */
    private const SHOP_PRICE_KINDS = ['cash', 'qris', 'hellom', 'custom'];

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
        $engine = $this->engine;
        $unit = $engine->unitCost((int) $product->id);
        $price = $this->price($product);
        $target = $this->marginTarget($product);
        $rounding = (string) $engine->settings->rounding;
        // revenue_pct overhead moves with the price: treat it like a fee when suggesting prices.
        $overheadPct = $engine->overheadRevenuePct();
        $overhead = $engine->overheadPerUnit((int) $product->id, $price);
        $fixedOverhead = $overheadPct > 0 ? 0.0 : $overhead;
        $full = $unit['direct'] + $overhead;
        $main = $engine->mainChannel();
        $mainPct = (float) ($main?->fee_pct ?? 0);
        $mainFlat = (float) ($main?->fee_flat ?? 0);
        // No recipe / batch cost yet: no margin, food cost or BEP (a Rp0 HPP would look very profitable).
        $incomplete = $unit['direct'] <= 0;
        $foodCost = $incomplete ? null : PriceAdvisor::foodCostPct($price, $unit['material'] + $unit['packaging']);

        $channels = [];
        if (!$product->is_subrecipe) {
            foreach ($engine->channels() as $channel) {
                $channelPrice = $this->channelPrice($product, $channel, $price);
                $channelOverhead = $overheadPct > 0 ? ($channelPrice ?? 0) * $overheadPct / 100 : $fixedOverhead;
                $channels[] = [
                    'id' => (int) $channel->id,
                    'name' => (string) $channel->name,
                    'kind' => (string) $channel->kind,
                    'fee_pct' => (float) $channel->fee_pct,
                    'fee_flat' => (int) $channel->fee_flat,
                    'price' => $channelPrice,
                    'own_price' => isset(((array) ($product->channel_prices ?? []))[(string) $channel->id]),
                    'margin_pct' => $channelPrice && $unit['direct'] > 0 ? PriceAdvisor::margin($channelPrice, $unit['direct'] + $channelOverhead, (float) $channel->fee_pct, (float) $channel->fee_flat) : null,
                    'suggested_price' => PriceAdvisor::suggest($unit['direct'] + $fixedOverhead, $target, (float) $channel->fee_pct + $overheadPct, (float) $channel->fee_flat, $rounding),
                    'is_main' => $main !== null && (int) $main->id === (int) $channel->id,
                ];
            }
        }

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
                'overhead' => round($overhead, 2),
                'full' => round($full, 2),
            ],
            'main_channel' => $main ? ['id' => (int) $main->id, 'name' => (string) $main->name, 'fee_pct' => $mainPct] : null,
            'incomplete' => $incomplete,
            'margin_pct' => $incomplete ? null : PriceAdvisor::margin($price, $full, $mainPct, $mainFlat),
            'food_cost_pct' => $foodCost,
            'food_cost_status' => PriceAdvisor::foodCostStatus($foodCost),
            'suggested_price' => $product->is_subrecipe || $incomplete ? null : PriceAdvisor::suggest($unit['direct'] + $fixedOverhead, $target, $mainPct + $overheadPct, $mainFlat, $rounding),
            'channels' => $channels,
            'variants' => $product->is_subrecipe ? [] : array_map(function ($variant) use ($engine, $full, $price, $target, $mainPct, $mainFlat, $overheadPct, $fixedOverhead, $unit, $rounding) {
                $extra = $engine->variantExtraCost((int) $variant->id);
                $extraCost = $extra['material'] + $extra['packaging'];
                $variantPrice = $price > 0 ? $price + (int) $variant->price_delta : 0;

                return [
                    'id' => (int) $variant->id,
                    'name' => (string) $variant->name,
                    'kind' => (string) $variant->kind,
                    'price_delta' => (int) $variant->price_delta,
                    'pos_option_value_id' => $variant->pos_option_value_id ? (int) $variant->pos_option_value_id : null,
                    'extra_cost' => round($extraCost, 2),
                    'full' => round($full + $extraCost, 2),
                    'price' => $variantPrice,
                    'margin_pct' => $variantPrice > 0 && $unit['direct'] > 0 ? PriceAdvisor::margin($variantPrice, $full + $extraCost, $mainPct, $mainFlat) : null,
                    'suggested_price' => $unit['direct'] > 0 ? PriceAdvisor::suggest($unit['direct'] + $extraCost + $fixedOverhead, $target, $mainPct + $overheadPct, $mainFlat, $rounding) : null,
                ];
            }, $engine->variantsOf((int) $product->id)),
            'bep' => $product->is_subrecipe || $incomplete ? null : $this->breakEven($price, $unit['direct'], $mainPct, $mainFlat),
            'is_archived' => (bool) $product->is_archived,
        ];
    }

    /** @return array{units_per_month: int, units_per_day: float, contribution: float}|null */
    private function breakEven(int $price, float $variableCost, float $feePct, float $feeFlat): ?array
    {
        $fixed = $this->engine->overheadTotal();
        $contribution = $price - $variableCost - ($price * $feePct / 100 + $feeFlat);
        if ($fixed <= 0 || $price <= 0 || $contribution <= 0) {
            return null;
        }
        $units = (int) ceil($fixed / $contribution);

        return ['units_per_month' => $units, 'units_per_day' => round($units / 30, 1), 'contribution' => round($contribution, 2)];
    }

    private function channelPrice(HppProduct $product, HppChannel $channel, int $shopPrice): ?int
    {
        $own = ((array) ($product->channel_prices ?? []))[(string) $channel->id] ?? null;
        if ($own !== null && (int) $own > 0) {
            return (int) $own;
        }

        return in_array((string) $channel->kind, self::SHOP_PRICE_KINDS, true) && $shopPrice > 0 ? $shopPrice : null;
    }
}
