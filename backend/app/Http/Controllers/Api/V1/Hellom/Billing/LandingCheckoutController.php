<?php

namespace App\Http\Controllers\Api\V1\Hellom\Billing;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Models\LandingBlock;
use App\Models\OrganizationLandingPage;
use App\Services\Hellom\LandingSaleService;
use App\Services\Landing\PaymentStarter;
use App\Services\Payments\GatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Public checkout for products written directly into landing page blocks (before
 * Fase 3). New pages sell landing_products through PublicStoreController.
 */
class LandingCheckoutController extends BaseApiController
{
    /**
     * Public, no-auth: a buyer purchases a landing-page product/PDF. The price comes from
     * the database; money is collected by the platform's active gateway (PaymentGateway
     * adapter). The order only becomes paid through a gateway-verified webhook or the
     * reconcile job — never from this request or the thank-you page.
     */
    public function publicLandingCheckout(Request $request, string $organizationSlug, GatewayRegistry $gateways, PaymentStarter $starter): JsonResponse
    {
        $page = OrganizationLandingPage::query()
            ->with('organization')
            ->whereHas('organization', fn ($query) => $query->where('slug', $organizationSlug)->whereNull('landing_suspended_at'))
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

        try {
            $payment = $starter->start($order);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), ['code' => strtoupper($gateway->name()) . '_LANDING_CHECKOUT_FAILED'], 422);
        }

        return $this->ok($payment, $payment['mode'] === 'qris' ? 'Checkout QRIS dibuat' : 'Checkout produk dibuat', 201);
    }
}
