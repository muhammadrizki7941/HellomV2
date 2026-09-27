<?php

namespace App\Http\Controllers\Api\V1\Hellom\Billing;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Http\Controllers\Api\V1\Hellom\Billing\Concerns\InteractsWithPaymentGateways;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payment gateway status (members) and gateway/manual-payment/runtime settings (super admin).
 */
class PaymentGatewayConfigController extends BaseApiController
{
    use InteractsWithPaymentGateways;

    public function gatewayStatus(): JsonResponse
    {
        $runtime = $this->gatewayRuntime()->getRuntimeConfig();
        $provider = $this->activeGatewayProvider();
        $activeConfig = $this->activeGatewayConfig();
        $balance = null;
        $manualConfig = $this->manualPaymentSettings()->publicOptions();

        if ($provider === 'xendit' && $activeConfig['is_ready']) {
            try {
                $balance = [
                    'currency' => 'IDR',
                    'amount' => (int) data_get($this->xendit()->getBalance('IDR'), 'balance', 0),
                ];
            } catch (\Throwable) {
                $balance = null;
            }
        }

        return $this->ok([
            'provider' => $provider,
            'active_provider' => $provider,
            'mode' => (string) ($activeConfig['mode'] ?? 'sandbox'),
            'is_ready' => (bool) ($activeConfig['is_ready'] ?? false),
            'checkout_mode' => (string) $runtime['checkout_mode'],
            'member_wallet_enabled' => (bool) $runtime['member_wallet_enabled'],
            'supports' => [
                'wallet_topup' => (bool) $runtime['member_wallet_enabled'] && (bool) ($activeConfig['is_ready'] ?? false),
                'subscription_wallet' => (bool) $runtime['member_wallet_enabled'],
                'subscription_direct_invoice' => (bool) ($activeConfig['is_ready'] ?? false),
                'virtual_account' => in_array($provider, ['xendit', 'doku'], true) ? (bool) ($activeConfig['is_ready'] ?? false) : false,
                'qris' => (bool) ($activeConfig['is_ready'] ?? false),
                'disbursement' => $provider === 'xendit' && (bool) ($activeConfig['is_ready'] ?? false),
                'webhook' => $this->providerCallbackTokenConfigured($provider),
                'manual_payment' => (bool) $manualConfig['enabled'] && count($manualConfig['methods']) > 0,
            ],
            'webhook' => [
                'path' => $this->providerWebhookPath($provider),
                'callback_token_configured' => $this->providerCallbackTokenConfigured($provider),
            ],
            'manual_confirmation' => [
                'enabled' => $runtime['checkout_mode'] === 'manual_confirmation',
                'label' => $runtime['checkout_mode'] === 'manual_confirmation'
                    ? 'Pembayaran langsung masuk antrean konfirmasi owner'
                    : 'Manual confirmation dimatikan',
            ],
            'providers' => [
                'xendit' => $this->xenditSettings()->publicConfigSummary(),
                'ipaymu' => $this->ipaymuSettings()->publicConfigSummary(),
                'doku' => $this->dokuSettings()->publicConfigSummary(),
            ],
            'manual_payment' => $manualConfig,
            'balance' => $balance,
        ], 'Payment gateway status');
    }

    public function adminGatewayConfig(): JsonResponse
    {
        $runtime = $this->gatewayRuntime()->getRuntimeConfig();
        $xendit = $this->xenditSettings()->publicConfigSummary();
        $ipaymu = $this->ipaymuSettings()->publicConfigSummary();
        $doku = $this->dokuSettings()->publicConfigSummary();
        $xenditBalance = null;

        if ($xendit['is_ready']) {
            try {
                $xenditBalance = [
                    'currency' => 'IDR',
                    'amount' => (int) data_get($this->xendit()->getBalance('IDR'), 'balance', 0),
                ];
            } catch (\Throwable $exception) {
                $xenditBalance = [
                    'currency' => 'IDR',
                    'amount' => null,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $this->ok([
            'active_provider' => (string) $runtime['active_provider'],
            'checkout_mode' => (string) $runtime['checkout_mode'],
            'member_wallet_enabled' => (bool) $runtime['member_wallet_enabled'],
            'sale_commission_percent' => (float) $runtime['sale_commission_percent'],
            'providers' => [
                'xendit' => [
                    ...$xendit,
                    'webhook' => [
                        'path' => $this->providerWebhookPath('xendit'),
                        'callback_token_configured' => $xendit['callback_token_masked'] !== null,
                    ],
                    'balance' => $xenditBalance,
                ],
                'ipaymu' => [
                    ...$ipaymu,
                    'webhook' => [
                        'path' => $this->providerWebhookPath('ipaymu'),
                        'callback_token_configured' => $ipaymu['callback_token_masked'] !== null,
                    ],
                    'balance' => null,
                ],
                'doku' => [
                    ...$doku,
                    'webhook' => [
                        'path' => $this->providerWebhookPath('doku'),
                        'callback_token_configured' => $doku['callback_token_masked'] !== null,
                    ],
                    'balance' => null,
                ],
            ],
            'manual_payment' => $this->manualPaymentSettings()->getConfig(),
        ], 'Admin gateway config');
    }

    public function updateAdminGatewayConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'in:xendit,ipaymu,doku'],
            'secret_key' => ['nullable', 'string', 'min:10', 'max:255'],
            'client_id' => ['nullable', 'string', 'min:3', 'max:255'],
            'va' => ['nullable', 'string', 'min:3', 'max:100'],
            'api_key' => ['nullable', 'string', 'min:10', 'max:255'],
            'callback_token' => ['nullable', 'string', 'min:8', 'max:255'],
            'is_production' => ['required', 'boolean'],
            'va_channels' => ['nullable'],
            'payment_method_types' => ['nullable'],
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => ['string', 'max:30'],
        ]);

        $provider = (string) $validated['provider'];
        $previous = $provider === 'ipaymu'
            ? $this->ipaymuSettings()->getConfig()
            : ($provider === 'doku' ? $this->dokuSettings()->getConfig() : $this->xenditSettings()->getConfig());

        if ($provider === 'ipaymu') {
            $config = $this->ipaymuSettings()->saveConfig([
                'va' => $validated['va'] ?? null,
                'api_key' => $validated['api_key'] ?? null,
                'callback_token' => $validated['callback_token'] ?? null,
                'is_production' => $validated['is_production'],
                'payment_methods' => $validated['payment_methods'] ?? null,
            ]);
        } elseif ($provider === 'doku') {
            $config = $this->dokuSettings()->saveConfig([
                'client_id' => $validated['client_id'] ?? null,
                'secret_key' => $validated['secret_key'] ?? null,
                'callback_token' => $validated['callback_token'] ?? null,
                'is_production' => $validated['is_production'],
                'payment_method_types' => $validated['payment_method_types'] ?? null,
            ]);
        } else {
            $config = $this->xenditSettings()->saveConfig($validated);
        }

        $generatedCallbackToken = ($previous['callback_token'] ?? '') === ''
            && trim((string) ($validated['callback_token'] ?? '')) === ''
            && ($config['callback_token'] ?? '') !== ''
                ? $config['callback_token']
                : null;

        return $this->ok([
            'provider' => $provider,
            'generated_callback_token' => $generatedCallbackToken,
            'config' => $provider === 'ipaymu'
                ? [
                    ...$this->ipaymuSettings()->publicConfigSummary(),
                    'webhook' => [
                        'path' => $this->providerWebhookPath('ipaymu'),
                        'callback_token_configured' => ($config['callback_token'] ?? '') !== '',
                    ],
                ]
                : ($provider === 'doku'
                    ? [
                        ...$this->dokuSettings()->publicConfigSummary(),
                        'webhook' => [
                            'path' => $this->providerWebhookPath('doku'),
                            'callback_token_configured' => ($config['callback_token'] ?? '') !== '',
                        ],
                    ]
                : [
                    ...$this->xenditSettings()->publicConfigSummary(),
                    'webhook' => [
                        'path' => $this->providerWebhookPath('xendit'),
                        'callback_token_configured' => ($config['callback_token'] ?? '') !== '',
                    ],
                ]),
        ], 'Admin gateway config updated');
    }

    public function resetIpaymuConfig(): JsonResponse
    {
        $config = $this->ipaymuSettings()->resetConfig();

        return $this->ok([
            'provider' => 'ipaymu',
            'config' => [
                ...$this->ipaymuSettings()->publicConfigSummary(),
                'webhook' => [
                    'path' => $this->providerWebhookPath('ipaymu'),
                    'callback_token_configured' => ($config['callback_token'] ?? '') !== '',
                ],
            ],
        ], 'iPaymu config reset');
    }

    public function adminManualPaymentConfig(): JsonResponse
    {
        return $this->ok($this->manualPaymentSettings()->getConfig(), 'Manual payment config');
    }

    public function updateAdminManualPaymentConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'methods.bank_transfer.enabled' => ['nullable', 'boolean'],
            'methods.bank_transfer.label' => ['nullable', 'string', 'max:100'],
            'methods.bank_transfer.bank_name' => ['nullable', 'string', 'max:100'],
            'methods.bank_transfer.account_name' => ['nullable', 'string', 'max:100'],
            'methods.bank_transfer.account_number' => ['nullable', 'string', 'max:100'],
            'methods.bank_transfer.instructions' => ['nullable', 'string', 'max:1000'],
            'methods.gopay.enabled' => ['nullable', 'boolean'],
            'methods.gopay.label' => ['nullable', 'string', 'max:100'],
            'methods.gopay.account_name' => ['nullable', 'string', 'max:100'],
            'methods.gopay.account_number' => ['nullable', 'string', 'max:100'],
            'methods.gopay.instructions' => ['nullable', 'string', 'max:1000'],
            'methods.dana.enabled' => ['nullable', 'boolean'],
            'methods.dana.label' => ['nullable', 'string', 'max:100'],
            'methods.dana.account_name' => ['nullable', 'string', 'max:100'],
            'methods.dana.account_number' => ['nullable', 'string', 'max:100'],
            'methods.dana.instructions' => ['nullable', 'string', 'max:1000'],
            'methods.qris.enabled' => ['nullable', 'boolean'],
            'methods.qris.label' => ['nullable', 'string', 'max:100'],
            'methods.qris.instructions' => ['nullable', 'string', 'max:1000'],
            'images.bank_transfer' => ['nullable', 'image', 'max:4096'],
            'images.gopay' => ['nullable', 'image', 'max:4096'],
            'images.dana' => ['nullable', 'image', 'max:4096'],
            'images.qris' => ['nullable', 'image', 'max:4096'],
        ]);

        $payload = $validated;
        unset($payload['images']);

        foreach (['bank_transfer', 'gopay', 'dana', 'qris'] as $methodKey) {
            if ($request->hasFile("images.{$methodKey}")) {
                $path = $request->file("images.{$methodKey}")->store('hellom/manual-payments', 'public');
                data_set($payload, "methods.{$methodKey}.image_path", $path);
            }
        }

        return $this->ok(
            $this->manualPaymentSettings()->saveConfig($payload),
            'Manual payment config updated'
        );
    }

    public function checkoutRuntimeConfig(): JsonResponse
    {
        return $this->ok(
            $this->gatewayRuntime()->getRuntimeConfig(),
            'Checkout runtime config'
        );
    }

    public function updateCheckoutRuntimeConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'active_provider' => ['required', 'in:xendit,ipaymu,doku'],
            'checkout_mode' => ['required', 'in:manual_confirmation,gateway_automatic,xendit_automatic'],
            'member_wallet_enabled' => ['required', 'boolean'],
            'sale_commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return $this->ok(
            $this->gatewayRuntime()->saveRuntimeConfig($validated),
            'Checkout runtime config updated'
        );
    }
}
