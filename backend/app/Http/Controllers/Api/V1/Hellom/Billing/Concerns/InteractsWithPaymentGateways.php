<?php

namespace App\Http\Controllers\Api\V1\Hellom\Billing\Concerns;

use App\Models\User;
use App\Services\Hellom\DokuService;
use App\Services\Hellom\DokuSettingsService;
use App\Services\Hellom\IpaymuService;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Hellom\ManualPaymentSettingsService;
use App\Services\Hellom\PaymentGatewaySettingsService;
use App\Services\Hellom\XenditService;
use App\Services\Hellom\XenditSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Payment gateway accessors shared by the billing controllers (active provider,
 * provider settings/services, webhook + return URLs).
 */
trait InteractsWithPaymentGateways
{
    private function checkoutMode(): string
    {
        return (string) $this->gatewayRuntime()->getRuntimeConfig()['checkout_mode'];
    }

    private function isActiveGatewayReady(): bool
    {
        return (bool) ($this->activeGatewayConfig()['is_ready'] ?? false);
    }

    private function memberWalletEnabled(): bool
    {
        return (bool) $this->gatewayRuntime()->getRuntimeConfig()['member_wallet_enabled'];
    }

    private function activeGatewayProvider(): string
    {
        return (string) $this->gatewayRuntime()->getRuntimeConfig()['active_provider'];
    }

    /**
     * @return array<string,mixed>
     */
    private function activeGatewayConfig(): array
    {
        return $this->activeGatewayProvider() === 'ipaymu'
            ? $this->ipaymuSettings()->getConfig()
            : ($this->activeGatewayProvider() === 'doku'
                ? $this->dokuSettings()->getConfig()
                : $this->xenditSettings()->getConfig());
    }

    private function activeGatewayLabel(): string
    {
        return match ($this->activeGatewayProvider()) {
            'ipaymu' => 'iPaymu',
            'doku' => 'DOKU',
            default => 'Xendit',
        };
    }

    private function providerWebhookPath(string $provider): string
    {
        return match ($provider) {
            'ipaymu' => '/api/v1/hellom/webhooks/ipaymu',
            'doku' => '/api/v1/hellom/webhooks/doku',
            default => '/api/v1/hellom/webhooks/xendit',
        };
    }

    private function providerCallbackTokenConfigured(string $provider): bool
    {
        $config = $provider === 'ipaymu'
            ? $this->ipaymuSettings()->getConfig()
            : ($provider === 'doku'
                ? $this->dokuSettings()->getConfig()
                : $this->xenditSettings()->getConfig());

        return (string) ($config['callback_token'] ?? '') !== '';
    }

    /**
     * @param array<string,int|string> $params
     */
    private function ipaymuNotifyUrl(array $params): string
    {
        $query = array_filter([
            ...$params,
            'token' => (string) $this->ipaymuSettings()->getConfig()['callback_token'],
        ], fn ($value) => $value !== '' && $value !== 0);

        return url($this->providerWebhookPath('ipaymu')) . '?' . http_build_query($query);
    }

    /**
     * Browser return URL for the SPA after a gateway redirect. Uses the request
     * Origin (the dashboard) so the redirect reaches the user's app even on localhost.
     */
    private function checkoutReturnUrl(Request $request, string $intentToken, bool $cancel = false): string
    {
        $base = rtrim((string) ($request->headers->get('Origin') ?: config('app.url')), '/');
        $params = ['ipaymu_return' => 1, 'intent' => $intentToken];
        if ($cancel) {
            $params['cancel'] = 1;
        }

        return $base . '/dashboard/payments?' . http_build_query($params);
    }

    private function dokuNotifyUrl(): string
    {
        return url($this->providerWebhookPath('doku')) . '?token=' . urlencode((string) $this->dokuSettings()->getConfig()['callback_token']);
    }

    private function gatewayRuntime(): PaymentGatewaySettingsService
    {
        return app(PaymentGatewaySettingsService::class);
    }

    private function xenditSettings(): XenditSettingsService
    {
        return app(XenditSettingsService::class);
    }

    private function xendit(): XenditService
    {
        return app(XenditService::class);
    }

    private function ipaymuSettings(): IpaymuSettingsService
    {
        return app(IpaymuSettingsService::class);
    }

    private function ipaymu(): IpaymuService
    {
        return app(IpaymuService::class);
    }

    private function dokuSettings(): DokuSettingsService
    {
        return app(DokuSettingsService::class);
    }

    private function doku(): DokuService
    {
        return app(DokuService::class);
    }

    private function manualPaymentSettings(): ManualPaymentSettingsService
    {
        return app(ManualPaymentSettingsService::class);
    }

    private function buildCustomerReferenceId(User $user): string
    {
        return 'usr_' . (string) $user->id . '_' . Str::lower(Str::random(10));
    }
}
