<?php

namespace App\Services\Hpp;

use App\Models\Hpp\HppIngredient;
use App\Models\Hpp\HppProduct;
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
 * $overrides: ingredient_id => cost per use unit (simulation, per-outlet prices).
 */
final class CostEngine
{
    public const MAX_DEPTH = 6;

    /** @var array<int, HppIngredient> */
    private array $ingredients = [];

    /** @var array<int, HppProduct> */
    private array $products = [];

    /** @var array<int, list<HppRecipeItem>> */
    private array $items = [];

    /** @var array<int, array<string, float>> */
    private array $memo = [];

    public readonly HppSetting $settings;

    /** @param array<int, float> $overrides */
    public function __construct(public readonly int $organizationId, private readonly array $overrides = [])
    {
        $this->settings = HppSetting::for($organizationId);
        $this->ingredients = HppIngredient::query()->where('organization_id', $organizationId)->get()->keyBy('id')->all();
        $this->products = HppProduct::query()->where('organization_id', $organizationId)->get()->keyBy('id')->all();
        $productIds = array_keys($this->products);
        foreach (HppRecipeItem::query()->whereIn('product_id', $productIds ?: [0])->orderBy('sort_order')->orderBy('id')->get() as $item) {
            $this->items[(int) $item->product_id][] = $item;
        }
    }

    /** @param array<int, float> $overrides */
    public static function for(int $organizationId, array $overrides = []): self
    {
        return new self($organizationId, $overrides);
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
    public function lines(int $productId): array
    {
        $lines = [];
        foreach ($this->itemsOf($productId) as $item) {
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
