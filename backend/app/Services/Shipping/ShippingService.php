<?php

namespace App\Services\Shipping;

use App\Models\LandingProduct;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;

/**
 * Real courier rates for Hellom Page physical products. Results are cached (route + weight)
 * so the provider quota lasts; the checkout always asks here again for the rate the buyer
 * picked — a price sent by the browser is never trusted.
 */
final class ShippingService
{
    public const MODE = 'courier';

    private const DESTINATION_CACHE_DAYS = 7;

    public function __construct(private readonly ShippingSettings $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->isReady();
    }

    public function provider(): ShippingProvider
    {
        $config = $this->settings->all();
        $key = $this->settings->apiKey();
        if ($config['provider'] !== 'rajaongkir' || $key === null) {
            throw new ShippingException('Ongkir otomatis belum aktif. Hubungi tim Hellom.');
        }

        return new RajaOngkirProvider($config['base_url'], $key);
    }

    /** @return list<array{id:string,label:string,city:?string,province:?string,postal_code:?string}> */
    public function searchDestinations(string $query): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');
        if (mb_strlen($query) < 3) {
            return [];
        }
        $key = 'shipping:dest:' . md5(mb_strtolower($query));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $found = array_map(fn (ShippingDestination $d) => $d->toArray(), $this->provider()->searchDestinations($query, 10));
        Cache::put($key, $found, now()->addDays(self::DESTINATION_CACHE_DAYS));

        return $found;
    }

    /** Ship-from place and couriers of a shop, or null when not set up. @return array{origin:array{id:string,label:string}, couriers:list<string>}|null */
    public function shopSetup(Organization $organization): ?array
    {
        $setup = is_array($organization->landing_shipping) ? $organization->landing_shipping : [];
        $origin = $setup['origin'] ?? null;
        if (!is_array($origin) || empty($origin['id'])) {
            return null;
        }
        $platform = $this->settings->all()['couriers'];
        $couriers = array_values(array_intersect($platform, (array) ($setup['couriers'] ?? [])));

        return ['origin' => ['id' => (string) $origin['id'], 'label' => (string) ($origin['label'] ?? '')], 'couriers' => $couriers ?: $platform];
    }

    /** Couriers + prices for this product to the buyer's place. @return list<ShippingRate> */
    public function ratesFor(LandingProduct $product, int $quantity, string $destinationId): array
    {
        if ($product->type !== LandingProduct::TYPE_PHYSICAL || $product->shipping_mode !== self::MODE) {
            throw new ShippingException('Produk ini tidak memakai ongkir otomatis.');
        }
        $organization = Organization::query()->find($product->organization_id);
        $setup = $organization ? $this->shopSetup($organization) : null;
        if ($setup === null) {
            throw new ShippingException('Penjual belum mengatur alamat pengiriman. Hubungi penjual.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $destinationId)) {
            throw new ShippingException('Pilih kecamatan / kota tujuan dari daftar.');
        }
        // Rounded up to 100 g: same courier price, more cache hits.
        $weight = (int) (ceil(max(1, (int) $product->weight_grams) * max(1, $quantity) / 100) * 100);
        $couriers = $setup['couriers'];
        sort($couriers);
        $key = 'shipping:rates:' . md5(implode('|', [$setup['origin']['id'], $destinationId, $weight, implode(':', $couriers)]));

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return array_map(fn (array $row) => ShippingRate::fromCache($row), $cached);
        }
        $rates = $this->provider()->rates($setup['origin']['id'], $destinationId, $weight, $couriers);
        usort($rates, fn (ShippingRate $a, ShippingRate $b) => $a->cost <=> $b->cost);
        if ($rates !== []) {
            Cache::put($key, array_map(fn (ShippingRate $r) => $r->toCache(), $rates), now()->addHours((int) $this->settings->all()['cache_hours']));
        }

        return $rates;
    }

    /** The rate the buyer picked, priced again on the server. */
    public function rateFor(LandingProduct $product, int $quantity, string $destinationId, string $choice): ShippingRate
    {
        foreach ($this->ratesFor($product, $quantity, $destinationId) as $rate) {
            if (strcasecmp($rate->key(), $choice) === 0) {
                return $rate;
            }
        }
        throw new ShippingException('Pilihan kurir ini sudah tidak tersedia. Pilih kurir lagi.');
    }
}
