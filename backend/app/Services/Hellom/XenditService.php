<?php

namespace App\Services\Hellom;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class XenditService
{
    public function __construct(
        private readonly XenditSettingsService $settings
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function getBalance(string $currency = 'IDR'): array
    {
        return $this->request('GET', '/balance', [
            'query' => [
                'account_type' => 'CASH',
                'currency' => $currency,
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createPaymentSession(array $payload): array
    {
        $payload = $this->normalizeMetadataPayload($payload);

        return $this->request('POST', '/sessions', [
            'json' => $payload,
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createPayout(array $payload, ?string $idempotencyKey = null): array
    {
        $payload = $this->normalizeMetadataPayload($payload);

        return $this->request('POST', '/v2/payouts', [
            'headers' => [
                'Idempotency-key' => $idempotencyKey ?: (string) ($payload['reference_id'] ?? Str::uuid()->toString()),
            ],
            'json' => $payload,
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizeMetadataPayload(array $payload): array
    {
        $metadata = $payload['metadata'] ?? null;

        if (!is_array($metadata)) {
            return $payload;
        }

        $normalized = [];
        foreach ($metadata as $key => $value) {
            $normalized[(string) $key] = $this->stringifyMetadataValue($value);
        }

        $payload['metadata'] = $normalized;

        return $payload;
    }

    private function stringifyMetadataValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $config = $this->settings->getConfig();

        if ($config['secret_key'] === '') {
            throw new \RuntimeException('Xendit secret key belum dikonfigurasi.');
        }

        $headers = (array) ($payload['headers'] ?? []);
        $query = (array) ($payload['query'] ?? []);
        $json = $payload['json'] ?? null;

        if (str_starts_with($path, '/v3/payment_requests') || str_starts_with($path, '/v3/payments')) {
            $headers['api-version'] = '2024-11-11';
        }

        try {
            $response = Http::baseUrl('https://api.xendit.co')
                ->withBasicAuth($config['secret_key'], '')
                ->acceptJson()
                ->asJson()
                ->withHeaders($headers)
                ->timeout(30)
                ->send($method, $path, [
                    'query' => $query,
                    'json' => $json,
                ])
                ->throw();
        } catch (RequestException $exception) {
            $message = data_get($exception->response?->json(), 'message')
                ?: data_get($exception->response?->json(), 'error_code')
                ?: $exception->getMessage();

            throw new \RuntimeException('Xendit API error: ' . $message, previous: $exception);
        }

        return (array) $response->json();
    }
}
