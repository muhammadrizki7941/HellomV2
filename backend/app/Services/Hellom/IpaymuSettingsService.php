<?php

namespace App\Services\Hellom;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class IpaymuSettingsService
{
    private const VA = 'hellom_ipaymu_va';
    private const API_KEY = 'hellom_ipaymu_api_key';
    private const CALLBACK_TOKEN = 'hellom_ipaymu_callback_token';
    private const IS_PRODUCTION = 'hellom_ipaymu_is_production';
    private const PAYMENT_METHODS = 'hellom_ipaymu_payment_methods';

    /** iPaymu payment methods the super admin can toggle (value => label). */
    public const AVAILABLE_METHODS = [
        'qris' => 'QRIS',
        'va' => 'Virtual Account / Transfer Bank',
        'cstore' => 'Gerai Retail (Indomaret/Alfamart)',
        'cc' => 'Kartu Kredit',
    ];

    /**
     * @return array{
     *   va:string,
     *   api_key:string,
     *   callback_token:string,
     *   is_production:bool,
     *   mode:string,
     *   is_ready:bool,
     *   payment_methods:array<int,string>
     * }
     */
    public function getConfig(): array
    {
        $va = $this->readEncryptedSetting(self::VA) ?: (string) config('payments.providers.ipaymu.va', '');
        $apiKey = $this->readEncryptedSetting(self::API_KEY) ?: (string) config('payments.providers.ipaymu.api_key', '');
        $defaultCallbackToken = (string) config('payments.providers.ipaymu.callback_token', '');
        $callbackToken = $this->readEncryptedSetting(self::CALLBACK_TOKEN) ?: $defaultCallbackToken;
        if ($callbackToken === 'dev_ipaymu_callback_token') {
            $callbackToken = '';
        }
        $isProduction = filter_var(
            SystemSetting::get(self::IS_PRODUCTION, config('payments.providers.ipaymu.is_production', false)),
            FILTER_VALIDATE_BOOLEAN
        );

        return [
            'va' => $va,
            'api_key' => $apiKey,
            'callback_token' => $callbackToken,
            'is_production' => (bool) $isProduction,
            'mode' => $isProduction ? 'production' : 'sandbox',
            'is_ready' => $va !== '' && $apiKey !== '' && $callbackToken !== '',
            'payment_methods' => $this->readPaymentMethods(),
        ];
    }

    /** Enabled iPaymu payment channels (e.g. ['qris']). Falls back to all if unset/empty. */
    public function enabledPaymentMethods(): array
    {
        $methods = $this->readPaymentMethods();

        return $methods === [] ? array_keys(self::AVAILABLE_METHODS) : $methods;
    }

    /** @return array<int,string> */
    private function readPaymentMethods(): array
    {
        $raw = (string) SystemSetting::get(self::PAYMENT_METHODS, '');
        if ($raw === '') {
            // Default: all methods enabled (preserves prior behaviour).
            return array_keys(self::AVAILABLE_METHODS);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array_keys(self::AVAILABLE_METHODS);
        }

        return array_values(array_filter(
            array_map('strval', $decoded),
            fn (string $m) => array_key_exists($m, self::AVAILABLE_METHODS)
        ));
    }

    private function sanitizePaymentMethods(array $methods): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', $methods),
            fn (string $m) => array_key_exists($m, self::AVAILABLE_METHODS)
        )));
    }

    /**
     * @param array{
     *   va?:string|null,
     *   api_key?:string|null,
     *   callback_token?:string|null,
     *   is_production?:bool|null
     * } $payload
     * @return array{
     *   va:string,
     *   api_key:string,
     *   callback_token:string,
     *   is_production:bool,
     *   mode:string,
     *   is_ready:bool
     * }
     */
    public function saveConfig(array $payload): array
    {
        $current = $this->getConfig();

        $va = trim((string) ($payload['va'] ?? ''));
        if ($va !== '') {
            $this->writeEncryptedSetting(self::VA, $va);
            $current['va'] = $va;
        }

        $apiKey = trim((string) ($payload['api_key'] ?? ''));
        if ($apiKey !== '') {
            $this->writeEncryptedSetting(self::API_KEY, $apiKey);
            $current['api_key'] = $apiKey;
        }

        $callbackToken = trim((string) ($payload['callback_token'] ?? ''));
        $currentToken = $current['callback_token'];
        if ($currentToken === 'dev_ipaymu_callback_token') {
            $currentToken = '';
        }
        if ($callbackToken === '' && $currentToken === '') {
            $callbackToken = $this->generateCallbackToken();
        }
        if ($callbackToken !== '') {
            $this->writeEncryptedSetting(self::CALLBACK_TOKEN, $callbackToken);
            $current['callback_token'] = $callbackToken;
        }

        if (array_key_exists('is_production', $payload) && $payload['is_production'] !== null) {
            $current['is_production'] = (bool) $payload['is_production'];
            SystemSetting::set(self::IS_PRODUCTION, $current['is_production'] ? '1' : '0');
        }

        if (array_key_exists('payment_methods', $payload) && is_array($payload['payment_methods'])) {
            $methods = $this->sanitizePaymentMethods($payload['payment_methods']);
            // Never store an empty set (would block all payments) — keep QRIS as a safe floor.
            if ($methods === []) {
                $methods = ['qris'];
            }
            SystemSetting::set(self::PAYMENT_METHODS, json_encode(array_values($methods)));
            $current['payment_methods'] = $methods;
        } else {
            $current['payment_methods'] = $this->readPaymentMethods();
        }

        $current['mode'] = $current['is_production'] ? 'production' : 'sandbox';
        $current['is_ready'] = $current['va'] !== '' && $current['api_key'] !== '' && $current['callback_token'] !== '';

        return $current;
    }

    public function isReady(): bool
    {
        return $this->getConfig()['is_ready'];
    }

    /**
     * @return array{
     *   va:string,
     *   api_key:string,
     *   callback_token:string,
     *   is_production:bool,
     *   mode:string,
     *   is_ready:bool
     * }
     */
    public function resetConfig(): array
    {
        $this->writeEncryptedSetting(self::VA, '');
        $this->writeEncryptedSetting(self::API_KEY, '');
        $this->writeEncryptedSetting(self::CALLBACK_TOKEN, '');
        SystemSetting::set(self::IS_PRODUCTION, '0');

        return $this->getConfig();
    }

    /**
     * @return array{
     *   provider:string,
     *   mode:string,
     *   is_ready:bool,
     *   va_masked:string|null,
     *   api_key_masked:string|null,
     *   callback_token_masked:string|null
     * }
     */
    public function publicConfigSummary(): array
    {
        $config = $this->getConfig();

        return [
            'provider' => 'ipaymu',
            'mode' => $config['mode'],
            'is_ready' => $config['is_ready'],
            'va_masked' => $this->maskValue($config['va']),
            'api_key_masked' => $this->maskValue($config['api_key']),
            'callback_token_masked' => $this->maskValue($config['callback_token']),
            'payment_methods' => $config['payment_methods'],
            'available_payment_methods' => self::AVAILABLE_METHODS,
        ];
    }

    private function readEncryptedSetting(string $key): string
    {
        $value = (string) SystemSetting::get($key, '');
        if ($value === '') {
            return '';
        }

        try {
            return (string) Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }

    private function writeEncryptedSetting(string $key, string $value): void
    {
        SystemSetting::set($key, Crypt::encryptString($value));
    }

    private function generateCallbackToken(): string
    {
        return 'ipm_cb_' . Str::random(40);
    }

    private function maskValue(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
    }
}
