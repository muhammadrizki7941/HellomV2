<?php

namespace App\Services\Hellom;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DokuService
{
    public function __construct(
        private readonly DokuSettingsService $settings
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createCheckout(array $payload): array
    {
        return $this->request('POST', '/checkout/v1/payment', $payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $payload): array
    {
        $config = $this->settings->getConfig();

        if ($config['client_id'] === '' || $config['secret_key'] === '') {
            throw new \RuntimeException('DOKU client ID atau secret key belum dikonfigurasi.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            throw new \RuntimeException('Payload DOKU tidak valid.');
        }

        $requestId = (string) Str::uuid();
        $requestTimestamp = now('UTC')->format('Y-m-d\TH:i:s\Z');
        $digest = base64_encode(hash('sha256', $body, true));
        $signature = base64_encode(hash_hmac('sha256', implode("\n", [
            'Client-Id:' . $config['client_id'],
            'Request-Id:' . $requestId,
            'Request-Timestamp:' . $requestTimestamp,
            'Request-Target:' . $path,
            'Digest:' . $digest,
        ]), $config['secret_key'], true));

        $baseUrl = $config['is_production'] ? 'https://api.doku.com' : 'https://api-sandbox.doku.com';

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withHeaders([
                    'Client-Id' => $config['client_id'],
                    'Request-Id' => $requestId,
                    'Request-Timestamp' => $requestTimestamp,
                    'Signature' => 'HMACSHA256=' . $signature,
                    'Content-Type' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->timeout(30)
                ->send($method, $path)
                ->throw();
        } catch (RequestException $exception) {
            $message = data_get($exception->response?->json(), 'message.0')
                ?: data_get($exception->response?->json(), 'message')
                ?: $exception->getMessage();

            throw new \RuntimeException('DOKU API error: ' . $message, previous: $exception);
        }

        return (array) $response->json();
    }
}
