<?php

namespace App\Services\Hellom;

use App\Models\SystemSetting;

class PaymentGatewaySettingsService
{
    private const ACTIVE_PROVIDER = 'hellom_active_payment_gateway';
    private const CHECKOUT_MODE = 'hellom_checkout_mode';
    private const MEMBER_WALLET_ENABLED = 'hellom_member_wallet_enabled';
    private const SALE_COMMISSION_PERCENT = 'hellom_sale_commission_percent';

    /**
     * @return array{
     *   active_provider:string,
     *   checkout_mode:string,
     *   member_wallet_enabled:bool,
     *   sale_commission_percent:float
     * }
     */
    public function getRuntimeConfig(): array
    {
        $provider = (string) SystemSetting::get(self::ACTIVE_PROVIDER, 'xendit');
        $checkoutMode = (string) SystemSetting::get(self::CHECKOUT_MODE, 'manual_confirmation');
        if ($checkoutMode === 'xendit_automatic') {
            $checkoutMode = 'gateway_automatic';
        }
        $memberWalletEnabled = filter_var(
            SystemSetting::get(self::MEMBER_WALLET_ENABLED, '0'),
            FILTER_VALIDATE_BOOLEAN
        );

        $commissionDefault = (float) config('payments.platform.sale_commission_percent', 5);
        $commissionRaw = SystemSetting::get(self::SALE_COMMISSION_PERCENT, null);
        $commission = $commissionRaw === null ? $commissionDefault : (float) $commissionRaw;
        $commission = max(0.0, min(100.0, $commission));

        return [
            'active_provider' => in_array($provider, ['xendit', 'ipaymu', 'doku'], true) ? $provider : 'xendit',
            'checkout_mode' => in_array($checkoutMode, ['manual_confirmation', 'gateway_automatic'], true)
                ? $checkoutMode
                : 'manual_confirmation',
            'member_wallet_enabled' => (bool) $memberWalletEnabled,
            'sale_commission_percent' => $commission,
        ];
    }

    /**
     * @param array{
     *   active_provider?:string|null,
     *   checkout_mode?:string|null,
     *   member_wallet_enabled?:bool|null
     * } $payload
     * @return array{
     *   active_provider:string,
     *   checkout_mode:string,
     *   member_wallet_enabled:bool
     * }
     */
    public function saveRuntimeConfig(array $payload): array
    {
        $current = $this->getRuntimeConfig();

        if (array_key_exists('active_provider', $payload) && in_array((string) $payload['active_provider'], ['xendit', 'ipaymu', 'doku'], true)) {
            $current['active_provider'] = (string) $payload['active_provider'];
            SystemSetting::set(self::ACTIVE_PROVIDER, $current['active_provider']);
        }

        $checkoutMode = (string) ($payload['checkout_mode'] ?? '');
        if ($checkoutMode === 'xendit_automatic') {
            $checkoutMode = 'gateway_automatic';
        }
        if (array_key_exists('checkout_mode', $payload) && in_array($checkoutMode, ['manual_confirmation', 'gateway_automatic'], true)) {
            $current['checkout_mode'] = $checkoutMode;
            SystemSetting::set(self::CHECKOUT_MODE, $current['checkout_mode']);
        }

        if (array_key_exists('member_wallet_enabled', $payload) && $payload['member_wallet_enabled'] !== null) {
            $current['member_wallet_enabled'] = (bool) $payload['member_wallet_enabled'];
            SystemSetting::set(self::MEMBER_WALLET_ENABLED, $current['member_wallet_enabled'] ? '1' : '0');
        }

        if (array_key_exists('sale_commission_percent', $payload) && $payload['sale_commission_percent'] !== null) {
            $commission = max(0.0, min(100.0, (float) $payload['sale_commission_percent']));
            $current['sale_commission_percent'] = $commission;
            SystemSetting::set(self::SALE_COMMISSION_PERCENT, (string) $commission);
        }

        return $current;
    }
}
