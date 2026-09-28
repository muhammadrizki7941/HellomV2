<?php

namespace App\Services\Pos;

use App\Models\Outlet;
use Carbon\CarbonImmutable;

/**
 * Per-outlet POS settings, stored in outlets.settings (JSON) under:
 *   pricing:       tax_percent, service_percent, rounding (0 | 100 | 500 | 1000)
 *   self_order:    accept_orders, require_confirmation, max_pending_per_table
 *   opening_hours: { mon..sun: [ {open:"08:00", close:"22:00"} ] }  (missing day = closed;
 *                  no opening_hours at all = always open, the behaviour before this existed)
 *   timezone:      default Asia/Jakarta
 */
final class OutletSettings
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    public const ROUNDING_STEPS = [0, 100, 500, 1000];

    private const DEFAULTS = [
        'pricing' => ['tax_percent' => 0.0, 'service_percent' => 0.0, 'rounding' => 0],
        'self_order' => ['accept_orders' => true, 'require_confirmation' => true, 'max_pending_per_table' => 3],
        'opening_hours' => null,
        'timezone' => 'Asia/Jakarta',
    ];

    private function __construct(private readonly array $values)
    {
    }

    public static function for(?Outlet $outlet): self
    {
        $stored = is_array($outlet?->settings) ? $outlet->settings : [];

        return new self([
            'pricing' => array_merge(self::DEFAULTS['pricing'], (array) ($stored['pricing'] ?? [])),
            'self_order' => array_merge(self::DEFAULTS['self_order'], (array) ($stored['self_order'] ?? [])),
            'opening_hours' => isset($stored['opening_hours']) && is_array($stored['opening_hours']) ? $stored['opening_hours'] : null,
            'timezone' => (string) ($stored['timezone'] ?? self::DEFAULTS['timezone']),
        ]);
    }

    public function taxPercent(): float
    {
        return max(0.0, min(100.0, (float) $this->values['pricing']['tax_percent']));
    }

    public function servicePercent(): float
    {
        return max(0.0, min(100.0, (float) $this->values['pricing']['service_percent']));
    }

    public function roundingStep(): int
    {
        $step = (int) $this->values['pricing']['rounding'];

        return in_array($step, self::ROUNDING_STEPS, true) ? $step : 0;
    }

    public function acceptsSelfOrders(): bool
    {
        return (bool) $this->values['self_order']['accept_orders'];
    }

    public function selfOrderNeedsConfirmation(): bool
    {
        return (bool) $this->values['self_order']['require_confirmation'];
    }

    public function maxPendingPerTable(): int
    {
        return max(1, (int) $this->values['self_order']['max_pending_per_table']);
    }

    public function isOpen(?CarbonImmutable $at = null): bool
    {
        $hours = $this->values['opening_hours'];
        if ($hours === null) {
            return true;
        }

        $now = ($at ?? CarbonImmutable::now())->setTimezone($this->values['timezone']);
        $day = self::DAYS[$now->dayOfWeekIso - 1];
        $time = $now->format('H:i');

        foreach ((array) ($hours[$day] ?? []) as $slot) {
            $open = (string) ($slot['open'] ?? '');
            $close = (string) ($slot['close'] ?? '');
            if ($open === '' || $close === '') {
                continue;
            }
            // Slots may run past midnight (e.g. 18:00–02:00).
            $inside = $close > $open ? ($time >= $open && $time < $close) : ($time >= $open || $time < $close);
            if ($inside) {
                return true;
            }
        }

        return false;
    }

    /** Human text for the customer page, e.g. "Hari ini buka 08:00–22:00". */
    public function todayText(?CarbonImmutable $at = null): ?string
    {
        $hours = $this->values['opening_hours'];
        if ($hours === null) {
            return null;
        }
        $now = ($at ?? CarbonImmutable::now())->setTimezone($this->values['timezone']);
        $slots = (array) ($hours[self::DAYS[$now->dayOfWeekIso - 1]] ?? []);
        if ($slots === []) {
            return 'Hari ini tutup';
        }

        return 'Hari ini buka ' . implode(', ', array_map(fn ($s) => ($s['open'] ?? '?') . '–' . ($s['close'] ?? '?'), $slots));
    }

    public function toArray(): array
    {
        return $this->values;
    }

    /** Validated merge of an update into the stored JSON (other keys are kept). */
    public static function merge(array $stored, array $update): array
    {
        if (isset($update['pricing'])) {
            $p = $update['pricing'];
            $stored['pricing'] = [
                'tax_percent' => round(max(0, min(100, (float) ($p['tax_percent'] ?? 0))), 2),
                'service_percent' => round(max(0, min(100, (float) ($p['service_percent'] ?? 0))), 2),
                'rounding' => in_array((int) ($p['rounding'] ?? 0), self::ROUNDING_STEPS, true) ? (int) $p['rounding'] : 0,
            ];
        }
        if (isset($update['self_order'])) {
            $s = $update['self_order'];
            $stored['self_order'] = [
                'accept_orders' => (bool) ($s['accept_orders'] ?? true),
                'require_confirmation' => (bool) ($s['require_confirmation'] ?? true),
                'max_pending_per_table' => max(1, min(20, (int) ($s['max_pending_per_table'] ?? 3))),
            ];
        }
        if (array_key_exists('opening_hours', $update)) {
            $stored['opening_hours'] = $update['opening_hours'] === null ? null : self::cleanHours((array) $update['opening_hours']);
        }
        if (isset($update['timezone']) && in_array($update['timezone'], timezone_identifiers_list(), true)) {
            $stored['timezone'] = $update['timezone'];
        }

        return $stored;
    }

    private static function cleanHours(array $hours): array
    {
        $clean = [];
        foreach (self::DAYS as $day) {
            $clean[$day] = array_values(array_filter(array_map(function ($slot) {
                $open = (string) ($slot['open'] ?? '');
                $close = (string) ($slot['close'] ?? '');

                return preg_match('/^\d{2}:\d{2}$/', $open) && preg_match('/^\d{2}:\d{2}$/', $close)
                    ? ['open' => $open, 'close' => $close]
                    : null;
            }, (array) ($hours[$day] ?? []))));
        }

        return $clean;
    }
}
