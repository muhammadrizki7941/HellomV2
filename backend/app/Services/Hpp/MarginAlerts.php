<?php

namespace App\Services\Hpp;

use App\Models\Hpp\HppProduct;
use App\Models\Hpp\HppPurchaseItem;

/**
 * Peringatan margin: products whose margin fell below target (after the main channel's fee), with the
 * likely cause — an ingredient in the recipe that got dearer in the last 30 days of purchases — and
 * the price that restores the target:
 *   "Harga Susu UHT naik 15%: margin Es Kopi Susu turun ke 28% (target 40%). Saran: Rp22.000."
 */
final class MarginAlerts
{
    public const MARGIN_TOLERANCE = 2.0;
    public const PRICE_RISE_MIN = 5.0;

    /** @return list<array<string, mixed>> */
    public function build(ProductCosting $costing): array
    {
        $engine = $costing->engine();
        $rises = $this->recentRises($engine->organizationId);
        $alerts = [];
        foreach ($engine->products() as $product) {
            /** @var HppProduct $product */
            if ($product->is_archived || $product->is_subrecipe) {
                continue;
            }
            $s = $costing->summary($product);
            if ($s['incomplete'] || $s['selling_price'] <= 0 || $s['margin_pct'] === null) {
                continue;
            }
            if ($s['margin_pct'] >= $s['target_margin_pct'] - self::MARGIN_TOLERANCE) {
                continue;
            }
            $cause = null;
            foreach (array_keys($engine->consumption((int) $product->id, 1)) as $ingredientId) {
                $rise = $rises[$ingredientId] ?? null;
                if ($rise && (!$cause || $rise['change_pct'] > $cause['change_pct'])) {
                    $cause = $rise;
                }
            }
            $suggested = $s['suggested_price'];
            $message = ($cause ? "Harga {$cause['name']} naik " . self::pct($cause['change_pct']) . ': ' : '')
                . "margin {$s['name']} " . ($cause ? 'turun ke ' : 'tinggal ') . self::pct($s['margin_pct'])
                . ' (target ' . self::pct($s['target_margin_pct']) . ').'
                . ($suggested && $suggested > $s['selling_price'] ? ' Saran: naikkan ke Rp' . number_format($suggested, 0, ',', '.') . '.' : '');
            $alerts[] = [
                'product_id' => (int) $product->id,
                'name' => $s['name'],
                'severity' => $s['margin_pct'] < $s['target_margin_pct'] / 2 ? 'critical' : 'warning',
                'margin_pct' => $s['margin_pct'],
                'target_margin_pct' => $s['target_margin_pct'],
                'food_cost_pct' => $s['food_cost_pct'],
                'selling_price' => $s['selling_price'],
                'suggested_price' => $suggested,
                'pos_product_id' => $s['pos_product_id'],
                'cause' => $cause,
                'message' => mb_strtoupper(mb_substr($message, 0, 1)) . mb_substr($message, 1),
            ];
        }
        usort($alerts, fn ($a, $b) => $a['margin_pct'] <=> $b['margin_pct']);

        return $alerts;
    }

    /** @return array<int, array{ingredient_id: int, name: string, change_pct: float}> biggest rise per ingredient, last 30 days */
    private function recentRises(int $organizationId): array
    {
        $rows = HppPurchaseItem::query()
            ->join('hpp_purchases', 'hpp_purchases.id', '=', 'hpp_purchase_items.purchase_id')
            ->join('hpp_ingredients', 'hpp_ingredients.id', '=', 'hpp_purchase_items.ingredient_id')
            ->where('hpp_purchases.organization_id', $organizationId)
            ->where('hpp_purchases.purchased_at', '>=', now()->subDays(30)->toDateString())
            ->where('hpp_purchase_items.previous_cost', '>', 0)
            ->get(['hpp_purchase_items.ingredient_id', 'hpp_ingredients.name', 'hpp_purchase_items.unit_cost', 'hpp_purchase_items.previous_cost']);
        $rises = [];
        foreach ($rows as $row) {
            $change = round(((float) $row->unit_cost - (float) $row->previous_cost) / (float) $row->previous_cost * 100, 1);
            if ($change >= self::PRICE_RISE_MIN && $change > ($rises[(int) $row->ingredient_id]['change_pct'] ?? 0)) {
                $rises[(int) $row->ingredient_id] = ['ingredient_id' => (int) $row->ingredient_id, 'name' => (string) $row->name, 'change_pct' => $change];
            }
        }

        return $rises;
    }

    private static function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',') . '%';
    }
}
