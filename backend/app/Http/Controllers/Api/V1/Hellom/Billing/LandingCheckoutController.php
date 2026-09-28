<?php

namespace App\Http\Controllers\Api\V1\Hellom\Billing;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Models\LandingBlock;
use App\Models\LandingPageOrder;
use App\Models\OrganizationLandingPage;
use App\Services\Hellom\LandingSaleService;
use App\Services\Payments\ChargeRequest;
use App\Services\Payments\GatewayRegistry;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public checkout for products sold on an organization's landing page.
 */
class LandingCheckoutController extends BaseApiController
{
    /**
     * Public, no-auth: a buyer purchases a landing-page product/PDF. The price comes from
     * the database; money is collected by the platform's active gateway (PaymentGateway
     * adapter). The order only becomes paid through a gateway-verified webhook or the
     * reconcile job — never from this request or the thank-you page.
     */
    public function publicLandingCheckout(Request $request, string $organizationSlug, GatewayRegistry $gateways): JsonResponse
    {
        $page = OrganizationLandingPage::query()
            ->with('organization')
            ->whereHas('organization', fn ($query) => $query->where('slug', $organizationSlug))
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        if (!$page instanceof OrganizationLandingPage) {
            return $this->fail('Halaman tidak ditemukan atau belum dipublish', ['code' => 'PUBLISHED_LANDING_PAGE_NOT_FOUND'], 404);
        }

        $gateway = $gateways->active();
        if (!$gateway->isReady()) {
            return $this->fail('Pembayaran sedang tidak tersedia. Silakan coba lagi nanti.', [
                'code' => 'GATEWAY_NOT_READY',
            ], 422);
        }

        $validated = $request->validate([
            'block_id' => ['required', 'string', 'max:64'],
            'buyer_name' => ['required', 'string', 'max:150'],
            'buyer_email' => ['required', 'email', 'max:150'],
            'buyer_phone' => ['nullable', 'string', 'max:40'],
        ]);

        $block = LandingBlock::query()
            ->where('landing_page_id', (int) $page->id)
            ->where('id', (int) $validated['block_id'])
            ->first();

        if (!$block instanceof LandingBlock || !in_array((string) $block->block_type, ['product', 'pdf'], true)) {
            return $this->fail('Produk tidak ditemukan', ['code' => 'PRODUCT_BLOCK_NOT_FOUND'], 404);
        }

        $content = is_array($block->content) ? $block->content : [];
        if ((string) $block->block_type === 'pdf' && (string) ($content['accessType'] ?? 'free') !== 'paid') {
            return $this->fail('Item ini gratis, tidak perlu checkout', ['code' => 'PRODUCT_NOT_PAID'], 422);
        }

        $sales = app(LandingSaleService::class);
        $price = $sales->parsePrice($content['price'] ?? 0);
        if ($price < 10000) {
            return $this->fail('Harga produk belum valid untuk pembayaran online', ['code' => 'INVALID_PRODUCT_PRICE'], 422);
        }

        $order = $sales->createPendingOrder($page, $block, [
            'name' => (string) $validated['buyer_name'],
            'email' => (string) $validated['buyer_email'],
            'phone' => isset($validated['buyer_phone']) ? (string) $validated['buyer_phone'] : null,
        ]);
        $referenceId = (string) $order->reference_id;

        try {
            $charge = $gateway->createCharge(new ChargeRequest(
                reference: $referenceId,
                amount: (int) $order->amount,
                productName: (string) $order->product_name,
                buyerName: (string) $order->buyer_name,
                buyerEmail: (string) $order->buyer_email,
                buyerPhone: $order->buyer_phone,
                // Thank-you page: polls the order status; it can never mark the order paid.
                returnUrl: FrontendUrl::to('/pesanan/' . $referenceId),
                notifyContext: [
                    'purpose' => 'landing_sale',
                    'organization_id' => (int) $page->organization_id,
                    'reference_id' => $referenceId,
                ],
            ));
        } catch (\Throwable $exception) {
            report($exception);
            $order->forceFill(['status' => LandingPageOrder::STATUS_FAILED, 'failed_at' => now()])->save();

            return $this->fail('Pembayaran belum bisa dibuat. Coba lagi sebentar lagi.', [
                'code' => strtoupper($gateway->name()) . '_LANDING_CHECKOUT_FAILED',
            ], 422);
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

        if ($charge->mode === 'qris') {
            return $this->ok([
                'reference_id' => $referenceId,
                'provider' => $charge->provider,
                'mode' => 'qris',
                'amount' => (int) $order->amount,
                'product_name' => (string) $order->product_name,
                'qr_image_url' => route('api.v1.hellom.public.landing.orders.qr', ['reference' => $referenceId]),
                'qr_string' => (string) $charge->qrString,
                'payment_url' => null,
                'status_url' => FrontendUrl::to('/pesanan/' . $referenceId),
            ], 'Checkout QRIS dibuat', 201);
        }

        if (!$charge->paymentUrl) {
            $order->forceFill(['status' => LandingPageOrder::STATUS_FAILED, 'failed_at' => now()])->save();

            return $this->fail('Halaman pembayaran tidak tersedia. Coba lagi.', ['code' => 'PAYMENT_URL_MISSING'], 422);
        }

        return $this->ok([
            'reference_id' => $referenceId,
            'provider' => $charge->provider,
            'mode' => 'redirect',
            'amount' => (int) $order->amount,
            'product_name' => (string) $order->product_name,
            'payment_url' => $charge->paymentUrl,
            'status_url' => FrontendUrl::to('/pesanan/' . $referenceId),
        ], 'Checkout produk dibuat', 201);
    }
}
