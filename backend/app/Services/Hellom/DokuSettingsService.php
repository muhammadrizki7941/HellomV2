<?php

namespace App\Services\Hellom;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class DokuSettingsService
{
    private const CLIENT_ID = 'hellom_doku_client_id';
    private const SECRET_KEY = 'hellom_doku_secret_key';
    private const CALLBACK_TOKEN = 'hellom_doku_callback_token';
    private const IS_PRODUCTION = 'hellom_doku_is_production';
    private const PAYMENT_METHODS = 'hellom_doku_payment_methods';

    /**
     * @return array{
     *   client_id:string,
     *   secret_key:string,
     *   callback_token:string,
     *   is_production:bool,
     *   mode:string,
     *   payment_method_types:array<int,string>,
     *   is_ready:bool
     * }
     */
    public function getConfig(): array
    {
        $clientId = $this->readEncryptedSetting(self::CLIENT_ID) ?: (string) config('payments.providers.doku.client_id', '');
        $secretKey = $this->readEncryptedSetting(self::SECRET_KEY) ?: (string) config('payments.providers.doku.secret_key', '');
        $callbackToken = $this->readEncryptedSetting(self::CALLBACK_TOKEN) ?: (string) config('payments.providers.doku.callback_token', '');
        if ($callbackToken === 'dev_doku_callback_token') {
            $callbackToken = '';
        }

        $isProduction = filter_var(
            SystemSetting::get(self::IS_PRODUCTION, config('payments.providers.doku.is_production', false)),
            FILTER_VALIDATE_BOOLEAN
        );

        $paymentMethodTypes = $this->readPaymentMethods();

        return [
            'client_id' => $clientId,
            'secret_key' => $secretKey,
            'callback_token' => $callbackToken,
            'is_production' => (bool) $isProduction,
            'mode' => $isProduction ? 'production' : 'sandbox',
            'payment_method_types' => $paymentMethodTypes,
            'is_ready' => $clientId !== '' && $secretKey !== '' && $callbackToken !== '',
        ];
    }

    /**
     * @param array{
     *   client_id?:string|null,
     *   secret_key?:string|null,
     *   callback_token?:string|null,
     *   is_production?:bool|null,
     *   payment_method_types?:array<int,string>|string|null
     * } $payload
     * @return array{
     *   client_id:string,
     *   secret_key:string,
     *   callback_token:string,
     *   is_production:bool,
     *   mode:string,
     *   payment_method_types:array<int,string>,
     *   is_ready:bool
     * }
     */
    public function saveConfig(array $payload): array
    {
        $current = $this->getConfig();

        $clientId = trim((string) ($payload['client_id'] ?? ''));
        if ($clientId !== '') {
            $this->writeEncryptedSetting(self::CLIENT_ID, $clientId);
            $current['client_id'] = $clientId;
        }

        $secretKey = trim((string) ($payload['secret_key'] ?? ''));
        if ($secretKey !== '') {
            $this->writeEncryptedSetting(self::SECRET_KEY, $secretKey);
            $current['secret_key'] = $secretKey;
        }

        $callbackToken = trim((string) ($payload['callback_token'] ?? ''));
        if ($callbackToken === '' && $current['callback_token'] === '') {
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

        if (array_key_exists('payment_method_types', $payload)) {
            $current['payment_method_types'] = $this->normalizePaymentMethods($payload['payment_method_types']);
            SystemSetting::set(self::PAYMENT_METHODS, json_encode($current['payment_method_types']));
        }

        $current['mode'] = $current['is_production'] ? 'production' : 'sandbox';
        $current['is_ready'] = $current['client_id'] !== '' && $current['secret_key'] !== '' && $current['callback_token'] !== '';

        return $current;
    }

    public function isReady(): bool
    {
        return $this->getConfig()['is_ready'];
    }

    /**
     * @return array{
     *   provider:string,
     *   mode:string,
     *   is_ready:bool,
     *   client_id_masked:string|null,
     *   secret_key_masked:string|null,
     *   callback_token_masked:string|null,
     *   payment_method_types:array<int,string>
     * }
     */
    public function publicConfigSummary(): array
    {
        $config = $this->getConfig();

        return [
            'provider' => 'doku',
            'mode' => $config['mode'],
            'is_ready' => $config['is_ready'],
            'client_id_masked' => $this->maskValue($config['client_id']),
            'secret_key_masked' => $this->maskValue($config['secret_key']),
            'callback_token_masked' => $this->maskValue($config['callback_token']),
            'payment_method_types' => $config['payment_method_types'],
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

    /**
     * @param array<int,string>|string|null $value
     * @return array<int,string>
     */
    private function normalizePaymentMethods(array|string|null $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $filtered = collect($items)
            ->map(fn ($item) => strtoupper(trim((string) $item)))
            ->filter()
            ->reject(fn (string $item) => str_starts_with($item, 'EMONEY_'))
            ->values()
            ->all();

        return $filtered !== [] ? $filtered : ['VIRTUAL_ACCOUNT_BCA', 'VIRTUAL_ACCOUNT_BANK_MANDIRI', 'QRIS'];
    }

    /**
     * @return array<int,string>
     */
    private function readPaymentMethods(): array
    {
        $stored = SystemSetting::get(self::PAYMENT_METHODS, '');
        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                return $this->normalizePaymentMethods($decoded);
            }
        }

        $defaults = config('payments.providers.doku.payment_method_types', ['VIRTUAL_ACCOUNT_BCA', 'VIRTUAL_ACCOUNT_BANK_MANDIRI', 'QRIS']);

        return $this->normalizePaymentMethods(is_array($defaults) ? $defaults : null);
    }

    private function generateCallbackToken(): string
    {
        return 'doku_cb_' . Str::random(40);
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
