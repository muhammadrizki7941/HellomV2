<?php

namespace App\Services\Hpp;

/**
 * Selling-price rules of the HPP calculator.
 *
 *   price = (HPP + fee flat) ÷ (1 − target margin − channel fee %), then rounded UP
 *   (ke Rp100 / Rp500 / ribuan / harga psikologis Rp19.900) so the margin is never lower.
 *
 * Food cost % = (bahan + kemasan) ÷ harga jual: hijau < 35%, kuning 35–45%, merah > 45%.
 */
final class PriceAdvisor
{
    public const FOOD_COST_GREEN = 35.0;
    public const FOOD_COST_RED = 45.0;

    /** Null when margin + fee leave nothing to cover the cost (≥ 95%). */
    public static function suggest(float $cost, float $marginPct, float $feePct = 0.0, float $feeFlat = 0.0, string $rounding = '1000'): ?int
    {
        $denominator = 1 - $marginPct / 100 - $feePct / 100;
        if ($cost <= 0 || $denominator <= 0.05) {
            return null;
        }

        return self::round(($cost + $feeFlat) / $denominator, $rounding);
    }

    public static function round(float $price, string $rounding): int
    {
        $price = (int) ceil($price - 0.0001);

        return match ($rounding) {
            '100' => (int) (ceil($price / 100) * 100),
            '500' => (int) (ceil($price / 500) * 500),
            '1000' => (int) (ceil($price / 1000) * 1000),
            // Rp19.900 style: just under the next thousand, never below the raw price.
            'psych' => (function () use ($price): int {
                $candidate = (int) (ceil(($price + 100) / 1000) * 1000) - 100;

                return $candidate >= $price ? $candidate : $candidate + 1000;
            })(),
            default => $price,
        };
    }

    /** Margin after cost and channel fee, in % of the price. */
    public static function margin(int $price, float $cost, float $feePct = 0.0, float $feeFlat = 0.0): ?float
    {
        if ($price <= 0) {
            return null;
        }
        $fee = $price * $feePct / 100 + $feeFlat;

        return round(($price - $cost - $fee) / $price * 100, 1);
    }

    public static function foodCostPct(int $price, float $materialAndPackaging): ?float
    {
        return $price > 0 ? round($materialAndPackaging / $price * 100, 1) : null;
    }

    /** green | yellow | red | null */
    public static function foodCostStatus(?float $pct): ?string
    {
        if ($pct === null) {
            return null;
        }

        return $pct < self::FOOD_COST_GREEN ? 'green' : ($pct <= self::FOOD_COST_RED ? 'yellow' : 'red');
    }
}
