<?php

namespace App\Services\Billing;

use App\Services\Hellom\DokuSettingsService;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Hellom\ManualPaymentSettingsService;
use App\Services\Hellom\PaymentGatewaySettingsService;
use App\Services\Hellom\XenditSettingsService;

/**
 * Which payment path a buyer gets, decided only by the super-admin settings
 * (Admin → Settings → Payment), for subscriptions and digital products alike:
 *
 * - "Konfirmasi manual owner"  → manual transfer (owner confirms).
 * - "Checkout otomatis"        → active gateway; the manual methods are only a backup
 *                                while that gateway is not configured/ready.
 *
 * Landing-page sales and wallet top-ups always need a gateway and do not use this.
 */
class PaymentPolicy
{
    public function __construct(
        private readonly PaymentGatewaySettingsService $runtime,
        private readonly ManualPaymentSettingsService $manual,
    ) {
    }

    /**
     * @return array{
     *   checkout_mode:string,
     *   provider:string,
     *   gateway_ready:bool,
     *   gateway:bool,
     *   manual:bool,
     *   direct_mode:string
     * }
     */
    public function checkoutOptions(): array
    {
        $runtime = $this->runtime->getRuntimeConfig();
        $mode = (string) $runtime['checkout_mode'];
        $provider = (string) $runtime['active_provider'];
        $gatewayReady = $this->gatewayReady($provider);

        $manualOptions = $this->manual->publicOptions();
        $manualConfigured = (bool) $manualOptions['enabled'] && count($manualOptions['methods']) > 0;

        $gateway = $mode === 'gateway_automatic' && $gatewayReady;
        $manual = $manualConfigured && ($mode === 'manual_confirmation' || !$gatewayReady);

        return [
            'checkout_mode' => $mode,
            'provider' => $provider,
            'gateway_ready' => $gatewayReady,
            'gateway' => $gateway,
            'manual' => $manual,
            // The single path used when the buyer does not choose (subscriptions).
            'direct_mode' => $gateway ? 'gateway_automatic' : ($manual ? 'manual_confirmation' : 'unavailable'),
        ];
    }

    public function gatewayReady(string $provider): bool
    {
        return match ($provider) {
            'ipaymu' => app(IpaymuSettingsService::class)->isReady(),
            'doku' => app(DokuSettingsService::class)->isReady(),
            default => app(XenditSettingsService::class)->isReady(),
        };
    }
}
