<?php

namespace App\Services\Hpp;

use App\Models\Hpp\HppChannel;
use App\Models\Hpp\HppIngredient;
use App\Models\Hpp\HppOverhead;
use App\Models\Hpp\HppProduct;
use App\Models\Hpp\HppProductVariant;
use App\Models\Hpp\HppRecipeItem;
use App\Models\Hpp\HppSetting;

/**
 * The one HPP engine (Mode Cepat, Resep and Pro all read from here). Loaded once per organization;
 * every number is per ONE unit of the product (a recipe/batch makes `yield_qty` units):
 *
 *   Mode Cepat   batch spend ÷ yield                         (Rp200.000 → 50 porsi = Rp4.000)
 *   Resep        Σ qty × ingredient cost (after yield loss) + sub-recipes, split into bahan/kemasan
 *   + labour     labour minutes × rate per hour,  + other     ongkir etc. — both per batch ÷ yield
 *
 *   overhead     per estimated unit sold (Rp5,8 jt ÷ 1.500 cup), % of revenue, or per labour hour
 *
 * $overrides: ingredient_id => cost per use unit (simulation, per-outlet prices).
 */
final class CostEngine
{
    public const MAX_DEPTH = 6;

    /** @var array<int, HppIngredient> */
    private array $ingredients = [];

    /** @var array<int, HppProduct> */
    private array $products = [];

    /** @var array<int, list<HppRecipeItem>> base recipe per product */
    private array $items = [];

    /** @var array<int, list<HppRecipeItem>> extra items per variant (per ONE unit) */
    private array $variantItems = [];

    /** @var array<int, list<HppProductVariant>> */
    private array $variants = [];

    /** @var array<int, array<string, float>> */
    private array $memo = [];

    public readonly HppSetting $settings;

    /** @var list<HppOverhead> */
    private array $overheads = [];

    /** @var list<HppChannel> */
    private array $channels = [];

    /** @param array<int, float> $overrides */
    public function __construct(public readonly int $organizationId, private readonly array $overrides = [], private readonly ?int $outletId = null, private readonly float $overheadFactor = 1.0)
    {
        $this->overheads = HppOverhead::query()->where('organization_id', $organizationId)->where('is_active', true)
            ->when($outletId !== null, fn ($q) => $q->whereIn('outlet_id', [0, $outletId]))->get()->all();
        $this->channels = HppChannel::query()->where('organization_id', $organizationId)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->all();
        $this->settings = HppSetting::for($organizationId);
        $this->ingredients = HppIngredient::query()->where('organization_id', $organizationId)->get()->keyBy('id')->all();
        $this->products = HppProduct::query()->where('organization_id', $organizationId)->get()->keyBy('id')->all();
        $productIds = array_keys($this->products);
        foreach (HppRecipeItem::query()->whereIn('product_id', $productIds ?: [0])->orderBy('sort_order')->orderBy('id')->get() as $item) {
            if ($item->variant_id) {
                $this->variantItems[(int) $item->variant_id][] = $item;
            } else {
                $this->items[(int) $item->product_id][] = $item;
            }
        }
        foreach (HppProductVariant::query()->whereIn('product_id', $productIds ?: [0])->orderBy('sort_order')->orderBy('id')->get() as $variant) {
            $this->variants[(int) $variant->product_id][] = $variant;
        }
    }

    /** @param array<int, float> $overrides */
    public static function for(int $organizationId, array $overrides = [], ?int $outletId = null, float $overheadFactor = 1.0): self
    {
        return new self($organizationId, $overrides, $outletId, $overheadFactor);
    }

    /** Fixed costs per month (active, this outlet + whole business). */
    public function overheadTotal(): float
    {
        return array_sum(array_map(fn (HppOverhead $o) => (int) $o->amount, $this->overheads)) * $this->overheadFactor;
    }

    /** Overhead as % of revenue (method revenue_pct), else 0. */
    public function overheadRevenuePct(): float
    {
        $revenue = (int) $this->settings->monthly_revenue;

        return $this->settings->overhead_method === 'revenue_pct' && $revenue > 0 ? $this->overheadTotal() / $revenue * 100 : 0.0;
    }

    /** Overhead carried by ONE unit of the product at $price (revenue_pct depends on the price). */
    public function overheadPerUnit(int $productId, int $price): float
    {
        $product = $this->products[$productId] ?? null;
        $total = $this->overheadTotal();
        if (!$product || $product->is_subrecipe || $total <= 0) {
            return 0.0;
        }

        return match ((string) $this->settings->overhead_method) {
            'revenue_pct' => $price * $this->overheadRevenuePct() / 100,
            'labor_hour' => (int) $this->settings->monthly_labor_hours > 0
                ? $total / (int) $this->settings->monthly_labor_hours * ((int) $product->labor_minutes / 60) / max(0.001, (float) $product->yield_qty)
                : 0.0,
            default => (int) $this->settings->monthly_units > 0 ? $total / (int) $this->settings->monthly_units : 0.0,
        };
    }

    /** @return list<HppChannel> */
    public function channels(): array
    {
        return $this->channels;
    }

    /** Channel used for the main margin / BEP: the chosen one, else QRIS, else the first. */
    public function mainChannel(): ?HppChannel
    {
        $chosen = (int) ($this->settings->main_channel_id ?? 0);
        foreach ($this->channels as $channel) {
            if ((int) $channel->id === $chosen) {
                return $channel;
            }
        }
        foreach ($this->channels as $channel) {
            if ($channel->kind === 'qris') {
                return $channel;
            }
        }

        return $this->channels[0] ?? null;
    }

    /** @return array<int, HppProduct> */
    public function products(): array
    {
        return $this->products;
    }

    public function product(int $id): ?HppProduct
    {
        return $this->products[$id] ?? null;
    }

    public function ingredient(int $id): ?HppIngredient
    {
        return $this->ingredients[$id] ?? null;
    }

    /** @return array<int, HppIngredient> */
    public function ingredients(): array
    {
        return $this->ingredients;
    }

    /** @return list<HppRecipeItem> */
    public function itemsOf(int $productId): array
    {
        return $this->items[$productId] ?? [];
    }

    /** @return list<HppProductVariant> */
    public function variantsOf(int $productId): array
    {
        return $this->variants[$productId] ?? [];
    }

    /** @return list<HppRecipeItem> */
    public function variantItemsOf(int $variantId): array
    {
        return $this->variantItems[$variantId] ?? [];
    }

    /**
     * Extra cost of a variant/modifier on top of one unit of the product.
     *
     * @return array{material: float, packaging: float}
     */
    public function variantExtraCost(int $variantId): array
    {
        $cost = ['material' => 0.0, 'packaging' => 0.0];
        foreach ($this->variantItemsOf($variantId) as $item) {
            if ($item->component_type === 'product') {
                $sub = $this->unitCost((int) $item->component_id);
                $cost['material'] += ($sub['material'] + $sub['labor'] + $sub['other']) * (float) $item->qty;
                $cost['packaging'] += $sub['packaging'] * (float) $item->qty;
                continue;
            }
            $ingredient = $this->ingredients[(int) $item->component_id] ?? null;
            if ($ingredient) {
                $cost[$ingredient->kind === 'packaging' ? 'packaging' : 'material'] += $this->ingredientUnitCost((int) $ingredient->id) * (float) $item->qty;
            }
        }

        return $cost;
    }

    /**
     * Raw ingredients used by $units of a product (+ chosen variants), sub-recipes expanded —
     * what POS backflush takes from stock. Mode Cepat products use nothing.
     *
     * @param list<int> $variantIds
     * @return array<int, float> ingredient_id => qty (use units)
     */
    public function consumption(int $productId, float $units, array $variantIds = []): array
    {
        $out = [];
        $this->expand($this->itemsOf($productId), $units / max(0.001, (float) ($this->products[$productId]->yield_qty ?? 1)), $out, [$productId]);
        foreach ($variantIds as $variantId) {
            $this->expand($this->variantItemsOf($variantId), $units, $out, [$productId]);
        }

        return $out;
    }

    /** @param list<HppRecipeItem> $items */
    private function expand(array $items, float $factor, array &$out, array $stack): void
    {
        if (count($stack) > self::MAX_DEPTH) {
            return;
        }
        foreach ($items as $item) {
            $qty = (float) $item->qty * $factor;
            if ($item->component_type === 'product') {
                $subId = (int) $item->component_id;
                $sub = $this->products[$subId] ?? null;
                if ($sub && !in_array($subId, $stack, true) && $sub->mode !== 'quick') {
                    $this->expand($this->itemsOf($subId), $qty / max(0.001, (float) $sub->yield_qty), $out, [...$stack, $subId]);
                }
                continue;
            }
            $out[(int) $item->component_id] = ($out[(int) $item->component_id] ?? 0) + $qty;
        }
    }

    /** Cost of one use unit of an ingredient (after yield loss, with overrides). */
    public function ingredientUnitCost(int $ingredientId): float
    {
        $ingredient = $this->ingredients[$ingredientId] ?? null;
        if (!$ingredient) {
            return 0.0;
        }

        return $ingredient->effectiveCost($this->overrides[$ingredientId] ?? null);
    }

    /**
     * Cost of ONE unit of the product.
     *
     * @return array{material: float, packaging: float, labor: float, other: float, direct: float}
     */
    public function unitCost(int $productId): array
    {
        $batch = $this->batchCost($productId, []);
        $yield = max(0.001, (float) ($this->products[$productId]->yield_qty ?? 1));
        $unit = array_map(fn (float $v) => $v / $yield, $batch);
        $unit['direct'] = $unit['material'] + $unit['packaging'] + $unit['labor'] + $unit['other'];

        return $unit;
    }

    /**
     * Recipe lines with their cost (per batch), for the product editor.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(int $productId, ?int $variantId = null): array
    {
        $lines = [];
        foreach ($variantId ? $this->variantItemsOf($variantId) : $this->itemsOf($productId) as $item) {
            [$name, $unit, $unitCost, $kind] = $this->componentInfo($item, []);
            $lines[] = [
                'id' => (int) $item->id,
                'component_type' => (string) $item->component_type,
                'component_id' => (int) $item->component_id,
                'name' => $name,
                'unit' => $unit,
                'kind' => $kind,
                'qty' => (float) $item->qty,
                'unit_cost' => round($unitCost, 4),
                'cost' => round($unitCost * (float) $item->qty, 2),
                'note' => $item->note,
            ];
        }

        return $lines;
    }

    /** Whether $componentId (a sub-recipe) is $productId or uses it — saving it would loop. */
    public function wouldLoop(int $productId, int $componentId): bool
    {
        if ($productId === $componentId) {
            return true;
        }
        $seen = [];
        $walk = function (int $id, int $depth) use (&$walk, &$seen, $productId): bool {
            if ($depth > self::MAX_DEPTH || isset($seen[$id])) {
                return $depth > self::MAX_DEPTH;
            }
            $seen[$id] = true;
            foreach ($this->itemsOf($id) as $item) {
                if ($item->component_type === 'product' && ((int) $item->component_id === $productId || $walk((int) $item->component_id, $depth + 1))) {
                    return true;
                }
            }

            return false;
        };

        return $walk($componentId, 0);
    }

    /** @return array{material: float, packaging: float, labor: float, other: float} */
    private function batchCost(int $productId, array $stack): array
    {
        if (isset($this->memo[$productId]) && $this->overrides === []) {
            return $this->memo[$productId];
        }
        $product = $this->products[$productId] ?? null;
        $cost = ['material' => 0.0, 'packaging' => 0.0, 'labor' => 0.0, 'other' => 0.0];
        if (!$product || in_array($productId, $stack, true) || count($stack) > self::MAX_DEPTH) {
            return $cost;
        }
        $stack[] = $productId;

        if ($product->mode === 'quick') {
            $cost['material'] = (float) $product->quick_batch_cost;
        } else {
            foreach ($this->itemsOf($productId) as $item) {
                if ($item->component_type === 'product') {
                    $sub = $this->batchCost((int) $item->component_id, $stack);
                    $subYield = max(0.001, (float) ($this->products[(int) $item->component_id]->yield_qty ?? 1));
                    foreach ($sub as $key => $value) {
                        $cost[$key] += $value / $subYield * (float) $item->qty;
                    }
                    continue;
                }
                $ingredient = $this->ingredients[(int) $item->component_id] ?? null;
                if (!$ingredient) {
                    continue;
                }
                $value = $this->ingredientUnitCost((int) $ingredient->id) * (float) $item->qty;
                $cost[$ingredient->kind === 'packaging' ? 'packaging' : 'material'] += $value;
            }
        }
        $cost['labor'] += (int) $product->labor_minutes / 60 * (int) $this->settings->labor_rate_per_hour;
        $cost['other'] += (float) $product->other_cost;

        return $this->memo[$productId] = $cost;
    }

    /** @return array{0: string, 1: string, 2: float, 3: string} name, unit, cost per unit, kind */
    private function componentInfo(HppRecipeItem $item, array $stack): array
    {
        if ($item->component_type === 'product') {
            $sub = $this->products[(int) $item->component_id] ?? null;
            if (!$sub) {
                return ['(sub-resep dihapus)', '', 0.0, 'subrecipe'];
            }
            $unit = $this->unitCost((int) $sub->id);

            return [(string) $sub->name, (string) $sub->yield_unit, $unit['direct'], 'subrecipe'];
        }
        $ingredient = $this->ingredients[(int) $item->component_id] ?? null;
        if (!$ingredient) {
            return ['(bahan dihapus)', '', 0.0, 'ingredient'];
        }

        return [(string) $ingredient->name, (string) $ingredient->use_unit, $this->ingredientUnitCost((int) $ingredient->id), (string) $ingredient->kind];
    }
}
