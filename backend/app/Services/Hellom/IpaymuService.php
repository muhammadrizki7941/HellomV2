<?php

namespace App\Services\Hellom;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class IpaymuService
{
    public function __construct(
        private readonly IpaymuSettingsService $settings
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createRedirectPayment(array $payload): array
    {
        return $this->request('POST', '/api/v2/payment', $this->normalizePaymentMethod($payload));
    }

    /**
     * iPaymu's redirect API expects `paymentMethod` as a single STRING, not an
     * array. If callers pass a list of enabled channels: one entry -> use that
     * single method; multiple/none -> drop the key so iPaymu shows all.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizePaymentMethod(array $payload): array
    {
        if (isset($payload['paymentMethod']) && is_array($payload['paymentMethod'])) {
            $methods = array_values(array_filter($payload['paymentMethod'], fn ($m) => is_string($m) && $m !== ''));
            if (count($methods) === 1) {
                $payload['paymentMethod'] = (string) $methods[0];
            } else {
                unset($payload['paymentMethod']);
            }
        }

        return $payload;
    }

    /**
     * Create a direct charge (VA / QRIS) that returns payment instructions
     * to render inside our own dashboard instead of redirecting out.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createDirectPayment(array $payload): array
    {
        return $this->request('POST', '/api/v2/payment/direct', $payload);
    }

    /**
     * Best-effort active status lookup for a transaction so we can confirm
     * a payment even if the webhook is delayed.
     *
     * @return array<string,mixed>
     */
    public function checkTransaction(int|string $transactionId): array
    {
        return $this->request('POST', '/api/v2/transaction', [
            'transactionId' => $transactionId,
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $payload): array
    {
        $config = $this->settings->getConfig();

        if ($config['va'] === '' || $config['api_key'] === '') {
            throw new \RuntimeException('iPaymu VA atau API key belum dikonfigurasi.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            throw new \RuntimeException('Payload iPaymu tidak valid.');
        }

        $timestamp = now()->format('YmdHis');
        $bodyHash = strtolower(hash('sha256', $body));
        $stringToSign = strtoupper($method) . ':' . $config['va'] . ':' . $bodyHash . ':' . $config['api_key'];
        $signature = hash_hmac('sha256', $stringToSign, $config['api_key']);
        $baseUrl = $config['is_production'] ? 'https://my.ipaymu.com' : 'https://sandbox.ipaymu.com';

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'va' => $config['va'],
                    'signature' => $signature,
                    'timestamp' => $timestamp,
                ])
                ->withBody($body, 'application/json')
                ->timeout(30)
                ->send($method, $path)
                ->throw();
        } catch (RequestException $exception) {
            $message = data_get($exception->response?->json(), 'Message')
                ?: data_get($exception->response?->json(), 'message')
                ?: $exception->getMessage();

            throw new \RuntimeException('iPaymu API error: ' . $message, previous: $exception);
        }

        return (array) $response->json();
    }
}
