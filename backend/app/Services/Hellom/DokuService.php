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
     * Status of a checkout by invoice number (our order reference).
     *
     * @return array<string,mixed>
     */
    public function getOrderStatus(string $invoiceNumber): array
    {
        return $this->request('GET', '/orders/v1/status/' . rawurlencode($invoiceNumber), null);
    }

    /**
     * DOKU signs notifications like requests: HMAC-SHA256 over Client-Id, Request-Id,
     * Request-Timestamp, Request-Target (the notification path) and Digest (SHA-256 of the body).
     */
    public function verifyNotificationSignature(string $clientId, string $requestId, string $timestamp, string $target, string $rawBody, string $signatureHeader): bool
    {
        $config = $this->settings->getConfig();
        if ($config['client_id'] === '' || $config['secret_key'] === '' || !hash_equals((string) $config['client_id'], $clientId)) {
            return false;
        }
        $digest = base64_encode(hash('sha256', $rawBody, true));
        $expected = 'HMACSHA256=' . base64_encode(hash_hmac('sha256', implode("\n", [
            'Client-Id:' . $clientId,
            'Request-Id:' . $requestId,
            'Request-Timestamp:' . $timestamp,
            'Request-Target:' . $target,
            'Digest:' . $digest,
        ]), $config['secret_key'], true));

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * @param array<string,mixed>|null $payload null for GET requests (no body / digest)
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $payload): array
    {
        $config = $this->settings->getConfig();

        if ($config['client_id'] === '' || $config['secret_key'] === '') {
            throw new \RuntimeException('DOKU client ID atau secret key belum dikonfigurasi.');
        }

        $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload !== null && !is_string($body)) {
            throw new \RuntimeException('Payload DOKU tidak valid.');
        }

        $requestId = (string) Str::uuid();
        $requestTimestamp = now('UTC')->format('Y-m-d\TH:i:s\Z');
        $components = [
            'Client-Id:' . $config['client_id'],
            'Request-Id:' . $requestId,
            'Request-Timestamp:' . $requestTimestamp,
            'Request-Target:' . $path,
        ];
        if ($body !== null) {
            $components[] = 'Digest:' . base64_encode(hash('sha256', $body, true));
        }
        $signature = base64_encode(hash_hmac('sha256', implode("\n", $components), $config['secret_key'], true));

        $baseUrl = $config['is_production'] ? 'https://api.doku.com' : 'https://api-sandbox.doku.com';

        try {
            $client = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withHeaders([
                    'Client-Id' => $config['client_id'],
                    'Request-Id' => $requestId,
                    'Request-Timestamp' => $requestTimestamp,
                    'Signature' => 'HMACSHA256=' . $signature,
                    'Content-Type' => 'application/json',
                ])
                ->timeout(30);
            if ($body !== null) {
                $client = $client->withBody($body, 'application/json');
            }
            $response = $client->send($method, $path)->throw();
        } catch (RequestException $exception) {
            $json = $exception->response?->json();
            $message = (string) (data_get($json, 'message.0') ?: data_get($json, 'message') ?: $exception->getMessage());
            $code = strtolower((string) (data_get($json, 'error.code') ?: data_get($json, 'error.type') ?: ''));

            // Production keys of a DOKU account that is still being verified answer 401
            // merchant_inactive: not a code problem, so say what to do instead.
            if (str_contains($code . ' ' . strtolower($message), 'merchant_inactive')) {
                throw new \RuntimeException($config['is_production']
                    ? 'Akun DOKU production belum aktif (masih diverifikasi DOKU). Pakai mode sandbox atau gateway lain dulu sampai akun disetujui.'
                    : 'Akun DOKU sandbox ini belum aktif. Cek Client ID dan Secret Key di dashboard DOKU.', previous: $exception);
            }

            throw new \RuntimeException('DOKU API error: ' . $message, previous: $exception);
        }

        return (array) $response->json();
    }
}
