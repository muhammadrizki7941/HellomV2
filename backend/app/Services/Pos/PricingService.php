<?php

namespace App\Services\Pos;

use App\Models\PosRewardRule;
use App\Models\Product;

/**
 * The only place that computes money for an order — used by both the cashier and
 * self-order, so the same cart always gives the same total.
 *
 *   subtotal       = Σ (base price + chosen add-ons) × qty
 *   net            = subtotal − reward discount − points discount   (never below 0)
 *   service charge = round(net × service%)
 *   tax            = round((net + service) × tax%)
 *   final          = net + service + tax, rounded to the outlet step (0 = no rounding)
 *
 * Frontends only show estimates; the numbers stored on the order come from here.
 */
final class PricingService
{
    /**
     * Price the cart lines. $products must be keyed by id and belong to the outlet.
     *
     * @param list<array{product_id:int, quantity:int, options?:array, notes?:?string}> $items
     * @param iterable<Product>|\Illuminate\Support\Collection $products
     * @return array{lines: list<array>, subtotal: int}
     * @throws PricingException
     */
    public function priceLines(array $items, $products): array
    {
        $lines = [];
        $subtotal = 0;
        $problems = [];

        foreach ($items as $index => $item) {
            /** @var Product|null $product */
            $product = $products[$item['product_id']] ?? null;
            if (!$product) {
                $problems[] = ['index' => $index, 'product_id' => (int) $item['product_id'], 'reason' => 'not_found', 'message' => 'Produk tidak ditemukan'];
                continue;
            }

            try {
                [$optionsTotal, $optionRows, $selected] = $this->resolveOptions($product, (array) ($item['options'] ?? []));
            } catch (PricingException $e) {
                $problems[] = ['index' => $index, 'product_id' => $product->id, 'name' => $product->name, 'reason' => 'options', 'message' => $e->getMessage()];
                continue;
            }

            $qty = max(1, (int) $item['quantity']);
            $unitPrice = (int) $product->price + $optionsTotal;
            $lineTotal = $unitPrice * $qty;
            $subtotal += $lineTotal;

            $lines[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'base_unit_price' => (int) $product->price,
                'options_total' => $optionsTotal,
                'unit_price' => $unitPrice,
                'qty' => $qty,
                'line_total' => $lineTotal,
                'selected_options' => $selected,
                'options' => $optionRows,
                'notes' => $item['notes'] ?? null,
            ];
        }

        if ($problems !== []) {
            throw new PricingException($problems[0]['message'] . (isset($problems[0]['name']) ? " ({$problems[0]['name']})" : ''), $problems);
        }

        return ['lines' => $lines, 'subtotal' => $subtotal];
    }

    public function rewardDiscount(?PosRewardRule $rule, int $subtotal): int
    {
        if (!$rule) {
            return 0;
        }

        $discount = match ($rule->reward_type) {
            'discount_percent' => (int) round($subtotal * (int) $rule->reward_value / 100),
            'discount_fixed' => (int) $rule->reward_value,
            'free_product' => (int) ($rule->scopedRewardProduct()?->price ?? 0),
            default => 0, // bonus_points gives points, not money
        };

        return max(0, min($discount, $subtotal));
    }

    /**
     * @return array{subtotal:int, discount_amount:int, points_discount_amount:int, service_amount:int,
     *               tax_amount:int, rounding_amount:int, final_amount:int,
     *               service_percent:float, tax_percent:float, rounding_step:int}
     */
    public function totals(OutletSettings $settings, int $subtotal, int $rewardDiscount = 0, int $pointsDiscount = 0): array
    {
        $rewardDiscount = max(0, min($rewardDiscount, $subtotal));
        $pointsDiscount = max(0, min($pointsDiscount, $subtotal - $rewardDiscount));
        $net = $subtotal - $rewardDiscount - $pointsDiscount;

        $service = (int) round($net * $settings->servicePercent() / 100);
        $tax = (int) round(($net + $service) * $settings->taxPercent() / 100);
        $beforeRounding = $net + $service + $tax;

        $step = $settings->roundingStep();
        $final = $step > 0 ? (int) (round($beforeRounding / $step) * $step) : $beforeRounding;

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $rewardDiscount,
            'points_discount_amount' => $pointsDiscount,
            'service_amount' => $service,
            'tax_amount' => $tax,
            'rounding_amount' => $final - $beforeRounding,
            'final_amount' => $final,
            'service_percent' => $settings->servicePercent(),
            'tax_percent' => $settings->taxPercent(),
            'rounding_step' => $step,
        ];
    }

    /**
     * Validate the chosen add-ons against the product's active options.
     *
     * @param array<int, array{option_id?:int, value_id?:int}> $selections
     * @return array{0:int, 1:list<array>, 2:list<array>} [options total, rows for order_item_options, normalised selection]
     * @throws PricingException
     */
    private function resolveOptions(Product $product, array $selections): array
    {
        $options = $product->relationLoaded('options')
            ? $product->options
            : $product->options()->with('values')->get();
        $options = $options->filter(fn ($o) => (bool) ($o->is_active ?? true));

        $byOption = [];
        foreach ($selections as $selection) {
            $byOption[(int) ($selection['option_id'] ?? 0)][] = (int) ($selection['value_id'] ?? 0);
        }

        $total = 0;
        $rows = [];
        $normalised = [];
        foreach ($options as $option) {
            $chosen = array_values(array_unique($byOption[$option->id] ?? []));
            unset($byOption[$option->id]);

            if ($chosen === [] && $option->is_required) {
                throw new PricingException("Pilih {$option->name}");
            }
            if (count($chosen) > 1 && $option->type !== 'multi') {
                throw new PricingException("{$option->name} hanya boleh dipilih satu");
            }

            $values = $option->values->filter(fn ($v) => (bool) ($v->is_active ?? true))->keyBy('id');
            foreach ($chosen as $valueId) {
                $value = $values[$valueId] ?? null;
                if (!$value) {
                    throw new PricingException("Pilihan {$option->name} tidak tersedia lagi");
                }
                $total += (int) $value->price_delta;
                $rows[] = ['product_option_id' => $option->id, 'product_option_value_id' => $value->id, 'option_name' => $option->name, 'value_name' => $value->name, 'price_delta' => (int) $value->price_delta];
                $normalised[] = ['option_id' => $option->id, 'value_id' => $value->id];
            }
        }

        if (array_filter(array_keys($byOption)) !== []) {
            throw new PricingException('Pilihan tambahan tidak tersedia lagi');
        }

        return [$total, $rows, $normalised];
    }
}
