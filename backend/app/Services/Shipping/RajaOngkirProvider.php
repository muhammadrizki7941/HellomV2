<?php

namespace App\Services\Shipping;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * RajaOngkir by Komerce (rajaongkir.komerce.id, API v1). Official docs:
 *   GET  /destination/domestic-destination?search=&limit=&offset=   header `key`
 *        → { meta{code,status,message}, data[{ id, label, subdistrict_name, district_name, city_name, province_name, zip_code }] }
 *   POST /calculate/domestic-cost  form: origin, destination, weight (gram), courier ("jne:jnt:…"), price=lowest
 *        → { meta{…}, data[{ name, code, service, description, cost, etd }] }
 */
final class RajaOngkirProvider implements ShippingProvider
{
    public function __construct(private readonly string $baseUrl, private readonly string $apiKey)
    {
    }

    public function name(): string
    {
        return 'rajaongkir';
    }

    public function searchDestinations(string $query, int $limit = 10): array
    {
        $data = $this->data($this->send(fn (PendingRequest $http) => $http->get('destination/domestic-destination', [
            'search' => $query, 'limit' => max(1, min(50, $limit)), 'offset' => 0,
        ])), allowNotFound: true);

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $out[] = new ShippingDestination(
                (string) $row['id'],
                (string) ($row['label'] ?? implode(', ', array_filter([$row['subdistrict_name'] ?? null, $row['district_name'] ?? null, $row['city_name'] ?? null, $row['province_name'] ?? null, $row['zip_code'] ?? null]))),
                isset($row['city_name']) ? (string) $row['city_name'] : null,
                isset($row['province_name']) ? (string) $row['province_name'] : null,
                isset($row['zip_code']) && $row['zip_code'] !== '0' ? (string) $row['zip_code'] : null,
            );
        }

        return $out;
    }

    public function rates(string $originId, string $destinationId, int $weightGrams, array $couriers): array
    {
        $data = $this->data($this->send(fn (PendingRequest $http) => $http->asForm()->post('calculate/domestic-cost', [
            'origin' => $originId,
            'destination' => $destinationId,
            'weight' => max(1, $weightGrams),
            'courier' => implode(':', $couriers),
            'price' => 'lowest',
        ])), allowNotFound: true);

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row) || !isset($row['code'], $row['service'], $row['cost']) || !is_numeric($row['cost']) || (int) $row['cost'] <= 0) {
                continue;
            }
            $out[] = new ShippingRate(
                strtolower((string) $row['code']),
                (string) ($row['name'] ?? strtoupper((string) $row['code'])),
                (string) $row['service'],
                isset($row['description']) ? (string) $row['description'] : null,
                (int) $row['cost'],
                isset($row['etd']) && $row['etd'] !== '' ? (string) $row['etd'] : null,
            );
        }

        return $out;
    }

    private function send(callable $call): Response
    {
        try {
            return $call(Http::baseUrl(rtrim($this->baseUrl, '/') . '/')->withHeaders(['key' => $this->apiKey])->acceptJson()->timeout(10)
                // One retry on a network failure only: a refused key or used-up quota would just burn a hit.
                ->retry(1, 300, fn ($e) => $e instanceof ConnectionException, throw: false));
        } catch (ConnectionException) {
            throw new ShippingException('Layanan ongkir sedang tidak bisa dihubungi. Coba lagi sebentar.');
        }
    }

    /** @return list<mixed> */
    private function data(Response $response, bool $allowNotFound = false): array
    {
        $json = $response->json();
        $code = (int) ($json['meta']['code'] ?? $response->status());
        if ($allowNotFound && $code === 404) {
            return []; // nothing found for this search / route
        }
        if (!$response->successful() || $code !== 200) {
            if (in_array($code, [401, 403], true)) {
                throw new ShippingException('API key layanan ongkir tidak valid. Periksa pengaturan ongkir di super admin.');
            }
            if ($code === 429) {
                throw new ShippingException('Kuota cek ongkir hari ini sudah habis. Coba lagi besok atau naikkan paket RajaOngkir.');
            }
            throw new ShippingException('Ongkir belum bisa dihitung saat ini. Coba lagi sebentar.');
        }

        return is_array($json['data'] ?? null) ? array_values($json['data']) : [];
    }
}
