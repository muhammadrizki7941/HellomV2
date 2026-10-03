<?php

namespace App\Services\Shipping;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * Super admin › Pengaturan › Ongkir. One JSON system setting + the API key stored encrypted
 * on its own (never returned by the API, only "is set").
 */
final class ShippingSettings
{
    private const KEY = 'shipping_settings';
    private const API_KEY = 'shipping_rajaongkir_api_key';

    public const PROVIDERS = ['none', 'rajaongkir'];

    /** Domestic couriers of RajaOngkir (Komerce), code => name. */
    public const COURIERS = [
        'jne' => 'JNE', 'jnt' => 'J&T Express', 'sicepat' => 'SiCepat', 'anteraja' => 'AnterAja', 'pos' => 'POS Indonesia',
        'ninja' => 'Ninja Xpress', 'tiki' => 'TIKI', 'lion' => 'Lion Parcel', 'sap' => 'SAP Express', 'ide' => 'ID Express',
        'wahana' => 'Wahana', 'rex' => 'REX', 'rpx' => 'RPX', 'ncs' => 'NCS', 'sentral' => 'Sentral Cargo', 'star' => 'Star Cargo', 'dse' => 'DSE',
    ];

    private const DEFAULTS = [
        'provider' => 'none',
        'base_url' => 'https://rajaongkir.komerce.id/api/v1',
        'couriers' => ['jne', 'jnt', 'sicepat', 'anteraja', 'pos', 'ninja', 'tiki', 'lion', 'sap', 'ide'],
        // Rates are cached per route & weight: the free plan has 100 cost checks a day.
        'cache_hours' => 12,
    ];

    /** @return array{provider:string, base_url:string, couriers:list<string>, cache_hours:int} */
    public function all(): array
    {
        $stored = json_decode((string) SystemSetting::get(self::KEY, '{}'), true);
        $values = array_replace(self::DEFAULTS, array_intersect_key(is_array($stored) ? $stored : [], self::DEFAULTS));
        $values['couriers'] = array_values(array_intersect((array) $values['couriers'], array_keys(self::COURIERS)));

        return $values;
    }

    public function apiKey(): ?string
    {
        $value = (string) SystemSetting::get(self::API_KEY, '');
        if ($value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null; // APP_KEY changed: treat as not set
        }
    }

    public function isReady(): bool
    {
        return $this->all()['provider'] === 'rajaongkir' && $this->apiKey() !== null && $this->all()['couriers'] !== [];
    }

    /** @param array{provider?:string, base_url?:string, couriers?:list<string>, cache_hours?:int, api_key?:?string} $data */
    public function update(array $data): array
    {
        $current = $this->all();
        if (isset($data['provider']) && in_array($data['provider'], self::PROVIDERS, true)) {
            $current['provider'] = $data['provider'];
        }
        if (isset($data['base_url']) && filter_var($data['base_url'], FILTER_VALIDATE_URL) && str_starts_with($data['base_url'], 'https://')) {
            $current['base_url'] = rtrim($data['base_url'], '/');
        }
        if (isset($data['couriers']) && is_array($data['couriers'])) {
            $current['couriers'] = array_values(array_intersect(array_keys(self::COURIERS), $data['couriers']));
        }
        if (isset($data['cache_hours'])) {
            $current['cache_hours'] = max(1, min(72, (int) $data['cache_hours']));
        }
        SystemSetting::set(self::KEY, json_encode($current));
        if (array_key_exists('api_key', $data) && is_string($data['api_key']) && trim($data['api_key']) !== '') {
            SystemSetting::set(self::API_KEY, Crypt::encryptString(trim($data['api_key'])));
        }

        return $this->publicPayload();
    }

    /** @return array<string, mixed> for the super admin (no secret). */
    public function publicPayload(): array
    {
        return $this->all() + ['api_key_set' => $this->apiKey() !== null, 'ready' => $this->isReady(), 'courier_labels' => self::COURIERS];
    }
}
