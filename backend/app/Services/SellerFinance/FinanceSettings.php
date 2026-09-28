<?php

namespace App\Services\SellerFinance;

use App\Models\SystemSetting;
use App\Services\Hellom\PaymentGatewaySettingsService;

/**
 * Super-admin settings for landing-page sales money (Admin › Keuangan Penjual).
 * Stored as one JSON system setting; the platform fee percent reuses the existing
 * "komisi penjualan" setting so the payment settings screen keeps working.
 */
final class FinanceSettings
{
    private const KEY = 'seller_finance_settings';

    public const METHODS = ['qris', 'va', 'ewallet', 'cc', 'retail', 'other'];

    public const METHOD_LABELS = [
        'qris' => 'QRIS',
        'va' => 'Virtual Account',
        'ewallet' => 'E-wallet',
        'cc' => 'Kartu kredit',
        'retail' => 'Gerai retail',
        'other' => 'Lainnya',
    ];

    /** Estimates used until the gateway reports the real fee of a transaction. */
    private const DEFAULTS = [
        'platform_fee_flat' => 0,
        // Hellom pays the gateway fee, so its fee is never below gateway fee + this margin.
        'min_margin_flat' => 0,
        'gateway_fees' => [
            'qris' => ['percent' => 0.7, 'flat' => 0],
            'va' => ['percent' => 0, 'flat' => 4500],
            'ewallet' => ['percent' => 2, 'flat' => 0],
            'cc' => ['percent' => 3, 'flat' => 2500],
            'retail' => ['percent' => 0, 'flat' => 5000],
            'other' => ['percent' => 2, 'flat' => 4500],
        ],
        'hold_days' => 0,
        'new_seller_hold_days' => 0,
        'new_seller_days' => 30,
        'min_withdrawal' => 50000,
        'withdrawal_fee_flat' => 0,
        'withdrawal_mode' => 'manual',   // manual | auto (auto needs a gateway with disbursement)
        'order_expiry_hours' => 24,
        'sla_hours' => 24,
        'sla_warn_hours' => 20,
        'bank_change_hold_hours' => 24,
    ];

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stored = json_decode((string) SystemSetting::get(self::KEY, '{}'), true);
        $stored = is_array($stored) ? $stored : [];
        $values = array_replace(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        $values['gateway_fees'] = array_replace(self::DEFAULTS['gateway_fees'], (array) ($stored['gateway_fees'] ?? []));
        $values['platform_fee_percent'] = (float) app(PaymentGatewaySettingsService::class)->getRuntimeConfig()['sale_commission_percent'];

        return $values;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Validated update (unknown keys ignored). Returns the new settings.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(array $input): array
    {
        $current = $this->all();

        if (array_key_exists('platform_fee_percent', $input)) {
            app(PaymentGatewaySettingsService::class)->saveRuntimeConfig([
                'sale_commission_percent' => max(0, min(50, (float) $input['platform_fee_percent'])),
            ]);
        }

        $intKeys = ['platform_fee_flat', 'min_margin_flat', 'hold_days', 'new_seller_hold_days', 'new_seller_days', 'min_withdrawal',
            'withdrawal_fee_flat', 'order_expiry_hours', 'sla_hours', 'sla_warn_hours', 'bank_change_hold_hours'];
        foreach ($intKeys as $key) {
            if (array_key_exists($key, $input)) {
                $current[$key] = max(0, (int) $input[$key]);
            }
        }
        if (isset($input['withdrawal_mode']) && in_array($input['withdrawal_mode'], ['manual', 'auto'], true)) {
            $current['withdrawal_mode'] = $input['withdrawal_mode'];
        }
        if (isset($input['gateway_fees']) && is_array($input['gateway_fees'])) {
            foreach (self::METHODS as $method) {
                if (isset($input['gateway_fees'][$method]) && is_array($input['gateway_fees'][$method])) {
                    $current['gateway_fees'][$method] = [
                        'percent' => round(max(0, min(20, (float) ($input['gateway_fees'][$method]['percent'] ?? 0))), 3),
                        'flat' => max(0, (int) ($input['gateway_fees'][$method]['flat'] ?? 0)),
                    ];
                }
            }
        }
        $current['order_expiry_hours'] = max(1, min(168, (int) $current['order_expiry_hours']));
        $current['sla_warn_hours'] = min((int) $current['sla_warn_hours'], (int) $current['sla_hours']);

        $store = array_intersect_key($current, self::DEFAULTS);
        SystemSetting::set(self::KEY, json_encode($store));

        return $this->all();
    }
}
