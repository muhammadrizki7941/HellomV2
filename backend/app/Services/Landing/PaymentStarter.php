<?php

namespace App\Services\Landing;

use App\Models\LandingPageOrder;
use App\Services\Hellom\IpaymuSettingsService;
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

    /** @return list<string> channel keys (see PaymentGateway::paymentOptions) */
    public function options(): array
    {
        $gateway = $this->gateways->active();

        return $gateway->isReady() ? $gateway->paymentOptions() : [];
    }

    /**
     * Options with labels for the checkout page, grouped like Hellom's own checkout.
     *
     * @return list<array{key:string,label:string,group:string}>
     */
    public function channels(): array
    {
        $direct = IpaymuSettingsService::DIRECT_CHANNELS;
        $fallback = ['qris' => ['QRIS', 'qris'], 'other' => ['Virtual Account & lainnya', 'other']];

        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => (string) ($direct[$key][2] ?? $fallback[$key][0] ?? strtoupper($key)),
            'group' => (string) ($direct[$key][3] ?? $fallback[$key][1] ?? 'other'),
        ], $this->options());
    }

    /**
     * @return array<string, mixed> response payload (mode qris|va|redirect)
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
            // Kept on the order (no secrets in gateway messages) so `landing:payments-check`
            // and support can see why no payment could be opened.
            $meta = is_array($order->metadata) ? $order->metadata : [];
            $meta['payment_error'] = mb_substr($exception->getMessage(), 0, 300);
            $order->forceFill(['metadata' => $meta])->save();
            $this->fail($order);

            throw new RuntimeException('Metode pembayaran ini sedang bermasalah. Coba metode lain atau ulangi sebentar lagi.', 0, $exception);
        }

        if ($charge->mode === 'redirect' && !$charge->paymentUrl) {
            $this->fail($order);

            throw new RuntimeException('Halaman pembayaran tidak tersedia. Coba lagi.');
        }

        // Payment instructions stay on the order so the status page can show them again.
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['payment'] = array_filter([
            'mode' => $charge->mode,
            'channel' => $preferredMethod,
            'channel_label' => $charge->channelLabel,
            'va_number' => $charge->vaNumber,
            'qr_string' => $charge->qrString,
            'qr_image_url' => $charge->qrImageUrl,
            'payment_url' => $charge->paymentUrl,
            'expires_at' => $charge->expiresAt,
        ], fn ($value) => $value !== null && $value !== '');
        // Read by the QR image endpoint.
        $meta['qr_image_url'] = (string) $charge->qrImageUrl;
        $meta['qr_string'] = (string) $charge->qrString;
        $order->forceFill([
            'provider' => $charge->provider,
            'gateway_ref' => (string) ($charge->gatewayRef ?? ''),
            'gateway_trx_id' => $charge->transactionId ?: null,
            'metadata' => $meta,
        ])->save();

        return [
            'reference_id' => $reference,
            'provider' => $charge->provider,
            'amount' => (int) $order->amount,
            'product_name' => (string) $order->product_name,
            'status_url' => $statusUrl,
            'expires_at' => $charge->expiresAt ?? optional($order->expires_at)->toIso8601String(),
        ] + self::instructions($order->fresh() ?? $order);
    }

    /**
     * What the buyer needs to pay a pending order on our page (also used by the status
     * page after a reload): QR, VA/retail code, or the provider's page.
     *
     * @return array{mode:string,channel_label:?string,va_number:?string,qr_string:?string,qr_image_url:?string,payment_url:?string}
     */
    public static function instructions(LandingPageOrder $order): array
    {
        $payment = (array) data_get($order->metadata, 'payment', []);
        $mode = (string) ($payment['mode'] ?? (data_get($order->metadata, 'qr_string') || data_get($order->metadata, 'qr_image_url') ? 'qris' : 'redirect'));
        $hasQr = (string) data_get($order->metadata, 'qr_string', '') !== '' || (string) data_get($order->metadata, 'qr_image_url', '') !== '';

        return [
            'mode' => $mode,
            'channel_label' => $payment['channel_label'] ?? ($mode === 'qris' ? 'QRIS' : null),
            'va_number' => $payment['va_number'] ?? null,
            'qr_string' => (string) data_get($order->metadata, 'qr_string', '') ?: null,
            'qr_image_url' => $mode === 'qris' && $hasQr ? route('api.v1.hellom.public.landing.orders.qr', ['reference' => (string) $order->reference_id]) : null,
            'payment_url' => $payment['payment_url'] ?? null,
        ];
    }

    /** No payment was opened: close the order and give the reservation back. */
    private function fail(LandingPageOrder $order): void
    {
        $this->payments->markFailed($order);
    }
}
