<?php

namespace App\Services\Hellom;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ManualPaymentSettingsService
{
    private const SETTINGS_KEY = 'hellom_manual_payment_settings';

    /**
     * @return array<string,mixed>
     */
    public function getConfig(): array
    {
        $raw = (string) SystemSetting::get(self::SETTINGS_KEY, '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        return $this->normalize(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function saveConfig(array $payload): array
    {
        $current = $this->getConfig();
        $merged = array_replace_recursive($current, $payload);
        $normalized = $this->normalize($merged);

        SystemSetting::set(self::SETTINGS_KEY, json_encode($normalized));

        return $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    public function publicOptions(): array
    {
        $config = $this->getConfig();
        $enabled = (bool) $config['enabled'];

        if (!$enabled) {
            return [
                'enabled' => false,
                'notes' => '',
                'methods' => [],
            ];
        }

        return [
            'enabled' => $enabled,
            'notes' => (string) $config['notes'],
            'methods' => collect($config['methods'])
                ->map(function (array $method, string $key): array {
                    return [
                        'key' => $key,
                        'enabled' => (bool) ($method['enabled'] ?? false),
                        'label' => (string) ($method['label'] ?? strtoupper($key)),
                        'account_name' => (string) ($method['account_name'] ?? ''),
                        'account_number' => (string) ($method['account_number'] ?? ''),
                        'bank_name' => (string) ($method['bank_name'] ?? ''),
                        'instructions' => (string) ($method['instructions'] ?? ''),
                        'image_url' => $this->publicUrl((string) ($method['image_path'] ?? '')),
                    ];
                })
                ->filter(fn (array $method) => $method['enabled'])
                ->values()
                ->all(),
        ];
    }

    private function normalize(array $config): array
    {
        $methods = $config['methods'] ?? [];

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'notes' => trim((string) ($config['notes'] ?? '')),
            'methods' => [
                'bank_transfer' => [
                    'enabled' => (bool) data_get($methods, 'bank_transfer.enabled', false),
                    'label' => trim((string) data_get($methods, 'bank_transfer.label', 'Transfer Bank')),
                    'bank_name' => trim((string) data_get($methods, 'bank_transfer.bank_name', '')),
                    'account_name' => trim((string) data_get($methods, 'bank_transfer.account_name', '')),
                    'account_number' => trim((string) data_get($methods, 'bank_transfer.account_number', '')),
                    'instructions' => trim((string) data_get($methods, 'bank_transfer.instructions', '')),
                    'image_path' => trim((string) data_get($methods, 'bank_transfer.image_path', '')),
                ],
                'gopay' => [
                    'enabled' => (bool) data_get($methods, 'gopay.enabled', false),
                    'label' => trim((string) data_get($methods, 'gopay.label', 'GoPay')),
                    'account_name' => trim((string) data_get($methods, 'gopay.account_name', '')),
                    'account_number' => trim((string) data_get($methods, 'gopay.account_number', '')),
                    'instructions' => trim((string) data_get($methods, 'gopay.instructions', '')),
                    'image_path' => trim((string) data_get($methods, 'gopay.image_path', '')),
                ],
                'dana' => [
                    'enabled' => (bool) data_get($methods, 'dana.enabled', false),
                    'label' => trim((string) data_get($methods, 'dana.label', 'DANA')),
                    'account_name' => trim((string) data_get($methods, 'dana.account_name', '')),
                    'account_number' => trim((string) data_get($methods, 'dana.account_number', '')),
                    'instructions' => trim((string) data_get($methods, 'dana.instructions', '')),
                    'image_path' => trim((string) data_get($methods, 'dana.image_path', '')),
                ],
                'qris' => [
                    'enabled' => (bool) data_get($methods, 'qris.enabled', false),
                    'label' => trim((string) data_get($methods, 'qris.label', 'QRIS Static')),
                    'instructions' => trim((string) data_get($methods, 'qris.instructions', '')),
                    'image_path' => trim((string) data_get($methods, 'qris.image_path', '')),
                ],
            ],
        ];
    }

    private function publicUrl(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '//'])) {
            return $path;
        }

        if (Str::startsWith($path, '/storage/')) {
            return $path;
        }

        if (Str::startsWith($path, 'storage/')) {
            return '/' . ltrim($path, '/');
        }

        $url = Storage::disk('public')->url($path);

        if (Str::startsWith($url, ['http://', 'https://', '//'])) {
            $parsedPath = parse_url($url, PHP_URL_PATH);
            if (is_string($parsedPath) && Str::contains($parsedPath, '/storage/')) {
                return '/storage/' . ltrim(Str::after($parsedPath, '/storage/'), '/');
            }
            return $url;
        }

        return $url;
    }
}
