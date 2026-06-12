<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Support\Facades\Http;

class MidtransQrisProvider implements DynamicQrisProvider
{
    public function name(): string
    {
        return 'midtrans';
    }

    public function configured(): bool
    {
        return (string) config('payments.providers.midtrans.server_key') !== '';
    }

    public function create(Order $order): array
    {
        $serverKey = (string) config('payments.providers.midtrans.server_key');
        $isProd = (bool) config('payments.providers.midtrans.is_production');

        if ($serverKey === '') {
            throw new \RuntimeException('Midtrans belum dikonfigurasi. Isi MIDTRANS_SERVER_KEY di .env');
        }

        $baseUrl = $isProd ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';

        // Core API charge for QRIS
        $payload = [
            'payment_type' => 'qris',
            'transaction_details' => [
                'order_id' => (string) $order->order_number,
                'gross_amount' => (int) $order->total_amount,
            ],
            // Optional customer details
            'customer_details' => [
                'first_name' => (string) ($order->customer_name ?: 'Customer'),
            ],
        ];

        $response = Http::timeout(10)
            ->withBasicAuth($serverKey, '')
            ->acceptJson()
            ->asJson()
            ->post($baseUrl.'/v2/charge', $payload);

        if (!$response->ok()) {
            $msg = 'Gagal membuat QRIS dinamis (Midtrans).';
            $body = $response->json();
            if (is_array($body) && isset($body['status_message'])) {
                $msg .= ' '.$body['status_message'];
            }
            throw new \RuntimeException($msg);
        }

        $data = $response->json();

        $reference = (string) (($data['transaction_id'] ?? '') ?: ($data['order_id'] ?? $order->order_number));

        $qrUrl = null;
        $qrString = null;

        // Midtrans usually returns 'actions' for QRIS
        if (is_array($data) && isset($data['actions']) && is_array($data['actions'])) {
            foreach ($data['actions'] as $action) {
                if (!is_array($action)) {
                    continue;
                }
                $name = (string) ($action['name'] ?? '');
                $url = (string) ($action['url'] ?? '');

                if ($name === 'generate-qr-code' && $url !== '') {
                    $qrUrl = $url;
                }

                if ($name === 'deeplink-redirect' && $url !== '' && !$qrUrl) {
                    // fallback if qr image URL isn't given
                    $qrUrl = $url;
                }
            }
        }

        // Some responses include 'qr_string'
        if (is_array($data) && isset($data['qr_string'])) {
            $qrString = (string) $data['qr_string'];
        }

        return [
            'reference' => $reference,
            'qr_url' => $qrUrl,
            'qr_string' => $qrString,
            'meta' => is_array($data) ? $data : ['raw' => $data],
        ];
    }
}
