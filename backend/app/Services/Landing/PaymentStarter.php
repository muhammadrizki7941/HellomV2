<?php

namespace App\Services\Landing;

use App\Models\LandingPageOrder;
use App\Services\Payments\ChargeRequest;
use App\Services\Payments\GatewayRegistry;
use App\Services\SellerFinance\LandingPaymentService;
use App\Support\FrontendUrl;
use RuntimeException;

/**
 * Opens the gateway payment for a pending landing order and returns what the checkout
 * page needs (QR on our page, or the provider's payment page). The order only becomes
 * paid through a gateway-verified webhook or the reconcile job.
 */
final class PaymentStarter
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly LandingPaymentService $payments,
    ) {
    }

    /** @return list<'qris'|'other'> */
    public function options(): array
    {
        $gateway = $this->gateways->active();

        return $gateway->isReady() ? $gateway->paymentOptions() : [];
    }

    /**
     * @return array<string, mixed> response payload (mode qris|redirect)
     *
     * @throws RuntimeException with a buyer-friendly message; the order is marked failed
     */
    public function start(LandingPageOrder $order, ?string $preferredMethod = null): array
    {
        $gateway = $this->gateways->active();
        $reference = (string) $order->reference_id;
        $statusUrl = FrontendUrl::to('/pesanan/' . $reference);

        try {
            $charge = $gateway->createCharge(new ChargeRequest(
                reference: $reference,
                amount: (int) $order->amount,
                productName: (string) $order->product_name,
                buyerName: (string) $order->buyer_name,
                buyerEmail: (string) $order->buyer_email,
                buyerPhone: $order->buyer_phone,
                // Thank-you page: polls the order status; it can never mark the order paid.
                returnUrl: $statusUrl,
                notifyContext: [
                    'purpose' => 'landing_sale',
                    'organization_id' => (int) $order->organization_id,
                    'reference_id' => $reference,
                ],
                preferredMethod: $preferredMethod,
            ));
        } catch (\Throwable $exception) {
            report($exception);
            $this->fail($order);

            throw new RuntimeException('Pembayaran belum bisa dibuat. Coba lagi sebentar lagi.', 0, $exception);
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if ($charge->mode === 'qris') {
            $meta['qr_image_url'] = (string) $charge->qrImageUrl;
            $meta['qr_string'] = (string) $charge->qrString;
        }
        $order->forceFill([
            'provider' => $charge->provider,
            'gateway_ref' => (string) ($charge->gatewayRef ?? ''),
            'gateway_trx_id' => $charge->transactionId ?: null,
            'metadata' => $meta,
        ])->save();

        $base = [
            'reference_id' => $reference,
            'provider' => $charge->provider,
            'amount' => (int) $order->amount,
            'product_name' => (string) $order->product_name,
            'status_url' => $statusUrl,
            'expires_at' => optional($order->expires_at)->toIso8601String(),
        ];
        if ($charge->mode === 'qris') {
            return $base + [
                'mode' => 'qris',
                'qr_image_url' => route('api.v1.hellom.public.landing.orders.qr', ['reference' => $reference]),
                'qr_string' => (string) $charge->qrString,
                'payment_url' => null,
            ];
        }
        if (!$charge->paymentUrl) {
            $this->fail($order);

            throw new RuntimeException('Halaman pembayaran tidak tersedia. Coba lagi.');
        }

        return $base + ['mode' => 'redirect', 'payment_url' => $charge->paymentUrl];
    }

    /** No payment was opened: close the order and give the reservation back. */
    private function fail(LandingPageOrder $order): void
    {
        $this->payments->markFailed($order);
    }
}
