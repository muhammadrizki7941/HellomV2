<?php

namespace App\Services\Hellom;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class XenditSettingsService
{
    private const SECRET_KEY = 'hellom_xendit_secret_key';
    private const CALLBACK_TOKEN = 'hellom_xendit_callback_token';
    private const IS_PRODUCTION = 'hellom_xendit_is_production';
    private const VA_CHANNELS = 'hellom_xendit_va_channels';

    /**
     * @return array{
     *   secret_key:string,
     *   callback_token:string,
     *   is_production:bool,
     *   mode:string,
     *   is_ready:bool,
     *   va_channels:array<int,string>
     * }
     */
    public function getConfig(): array
    {
        $secretKey = $this->readEncryptedSetting(self::SECRET_KEY) ?: (string) config('payments.providers.xendit.secret_key', '');
        $callbackToken = $this->readEncryptedSetting(self::CALLBACK_TOKEN) ?: (string) config('payments.providers.xendit.callback_token', '');
        $isProduction = filter_var(
            SystemSetting::get(self::IS_PRODUCTION, config('payments.providers.xendit.is_production', false)),
            FILTER_VALIDATE_BOOLEAN
        );
        $vaChannels = $this->readArraySetting(self::VA_CHANNELS);

        return [
            'secret_key' => $secretKey,
            'callback_token' => $callbackToken,
            'is_production' => (bool) $isProduction,
            'mode' => $isProduction ? 'production' : 'sandbox',
            'is_ready' => $secretKey !== '' && $callbackToken !== '',
            'va_channels' => $vaChannels,
        ];
    }

    /**
     * @param array{
     *   secret_key?:string|null,
     *   callback_token?:string|null,
     *   is_production?:bool|null,
     *   va_channels?:array<int,string>|string|null
     * } $payload
     * @return array{
     *   secret_key:string,
     *   callback_token:string,
     *   is_production:bool,
     *   mode:string,
     *   is_ready:bool,
     *   va_channels:array<int,string>
     * }
     */
    public function saveConfig(array $payload): array
    {
        $current = $this->getConfig();

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

        if (array_key_exists('va_channels', $payload)) {
            $channels = $this->normalizeVaChannels($payload['va_channels']);
            $current['va_channels'] = $channels;
            SystemSetting::set(self::VA_CHANNELS, json_encode($channels));
        }

        $current['mode'] = $current['is_production'] ? 'production' : 'sandbox';
        $current['is_ready'] = $current['secret_key'] !== '' && $current['callback_token'] !== '';

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
     *   secret_key_masked:string|null,
     *   callback_token_masked:string|null,
     *   va_channels:array<int,string>
     * }
     */
    public function publicConfigSummary(): array
    {
        $config = $this->getConfig();

        return [
            'provider' => 'xendit',
            'mode' => $config['mode'],
            'is_ready' => $config['is_ready'],
            'secret_key_masked' => $this->maskValue($config['secret_key']),
            'callback_token_masked' => $this->maskValue($config['callback_token']),
            'va_channels' => $config['va_channels'],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function readArraySetting(string $key): array
    {
        $raw = (string) SystemSetting::get($key, '');
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $values = array_values(array_filter($decoded, 'is_string'));

        return $this->normalizeVaChannels($values);
    }

    /**
     * @param array<int,string>|string|null $value
     * @return array<int,string>
     */
    private function normalizeVaChannels(array|string|null $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : explode(',', $value);

        $normalized = array_values(array_filter(array_map(
            fn ($item) => strtoupper(trim((string) $item)),
            $items
        ), fn ($item) => $item !== ''));

        return array_values(array_unique($normalized));
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
        return 'xnd_cb_' . Str::random(40);
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
