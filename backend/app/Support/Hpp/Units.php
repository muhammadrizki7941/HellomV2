<?php

namespace App\Support\Hpp;

/**
 * Units for the HPP calculator. A buy unit converts into a use unit with a factor:
 * 1 kg = 1000 g, so Rp180.000/kg is stored as Rp180/g. Known units fill the factor automatically;
 * packs (pak, dus, botol, …) need the user to say how many use units are inside.
 * Mirror: frontend src/pages/dashboard/hpp/units.ts.
 */
final class Units
{
    /** use unit => label */
    public const USE = [
        'g' => 'gram', 'ml' => 'ml', 'pcs' => 'pcs', 'porsi' => 'porsi', 'lembar' => 'lembar', 'cm' => 'cm', 'menit' => 'menit',
    ];

    /** buy unit => [use unit, factor]; null factor = the user fills it in. */
    public const BUY = [
        'kg' => ['g', 1000], 'ons' => ['g', 100], 'g' => ['g', 1],
        'liter' => ['ml', 1000], 'ml' => ['ml', 1],
        'pcs' => ['pcs', 1], 'lusin' => ['pcs', 12], 'kodi' => ['pcs', 20], 'tray' => ['pcs', 30],
        'rim' => ['lembar', 500], 'meter' => ['cm', 100], 'porsi' => ['porsi', 1],
        'pak' => [null, null], 'dus' => [null, null], 'botol' => [null, null], 'karung' => [null, null], 'sak' => [null, null], 'kantong' => [null, null], 'roll' => [null, null],
    ];

    /** Factor from buy unit to use unit when it is fixed (kg → g = 1000), else null. */
    public static function factor(string $buyUnit, string $useUnit): ?float
    {
        [$use, $factor] = self::BUY[$buyUnit] ?? [null, null];
        if ($buyUnit === $useUnit) {
            return 1.0;
        }

        return $use === $useUnit && $factor !== null ? (float) $factor : null;
    }
}
