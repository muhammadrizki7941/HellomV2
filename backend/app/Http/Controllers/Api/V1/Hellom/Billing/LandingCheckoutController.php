<?php

namespace App\Http\Controllers\Api\V1\Hellom\Billing;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Http\Controllers\Api\V1\Hellom\Billing\Concerns\InteractsWithPaymentGateways;
use App\Models\LandingBlock;
use App\Models\LandingPageOrder;
use App\Models\OrganizationLandingPage;
use App\Services\Hellom\LandingSaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public checkout for products sold on an organization's landing page.
 */
class LandingCheckoutController extends BaseApiController
{
    use InteractsWithPaymentGateways;

    /**
     * Public, no-auth: a buyer purchases a landing-page product/PDF. Money is
     * collected by the platform's active gateway; the seller is credited later
     * (pending wallet balance minus commission) via the webhook. Direct/WhatsApp
     * payment is intentionally NOT offered here — all sales go through the gateway.
     */
    public function publicLandingCheckout(Request $request, string $organizationSlug): JsonResponse
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

        if (!$this->isActiveGatewayReady()) {
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
        $provider = $this->activeGatewayProvider();
        $returnUrl = rtrim((string) ($request->headers->get('Origin') ?: config('app.url')), '/') . '/' . $organizationSlug;
        $ipaymuMethods = $this->ipaymuSettings()->enabledPaymentMethods();
        $qrisOnly = $provider === 'ipaymu' && count($ipaymuMethods) === 1 && in_array('qris', $ipaymuMethods, true);

        // QRIS-only on iPaymu: use a direct charge so we can show a downloadable QR in our own page.
        if ($qrisOnly) {
            try {
                $session = $this->ipaymu()->createDirectPayment([
                    'name' => (string) ($order->buyer_name ?: 'Pembeli'),
                    'email' => (string) $order->buyer_email,
                    'phone' => (string) ($order->buyer_phone ?: '08000000000'),
                    'amount' => (int) $order->amount,
                    'referenceId' => $referenceId,
                    'paymentMethod' => 'qris',
                    'paymentChannel' => 'qris',
                    'comments' => 'Pembelian: ' . (string) $order->product_name,
                    'returnUrl' => $returnUrl,
                    'notifyUrl' => $this->ipaymuNotifyUrl([
                        'purpose' => 'landing_sale',
                        'organization_id' => (int) $page->organization_id,
                        'reference_id' => $referenceId,
                    ]),
                ]);
            } catch (\Throwable $exception) {
                $order->forceFill(['status' => LandingPageOrder::STATUS_FAILED])->save();

                return $this->fail($exception->getMessage(), ['code' => 'IPAYMU_QRIS_FAILED'], 422);
            }

            $qrImageUrl = (string) (data_get($session, 'Data.QrImage') ?: data_get($session, 'Data.qr_image') ?: data_get($session, 'Data.Url') ?: '');
            $qrString = (string) (data_get($session, 'Data.QrString') ?: data_get($session, 'Data.QrContent') ?: data_get($session, 'Data.qr_string') ?: '');

            $meta = is_array($order->metadata) ? $order->metadata : [];
            $meta['qr_image_url'] = $qrImageUrl;
            $meta['qr_string'] = $qrString;
            $order->forceFill([
                'provider' => 'ipaymu',
                'gateway_ref' => (string) (data_get($session, 'Data.TransactionId') ?: data_get($session, 'Data.SessionID') ?: ''),
                'metadata' => $meta,
            ])->save();

            return $this->ok([
                'reference_id' => $referenceId,
                'provider' => 'ipaymu',
                'mode' => 'qris',
                'amount' => (int) $order->amount,
                'product_name' => (string) $order->product_name,
                'qr_image_url' => route('api.v1.hellom.public.landing.orders.qr', ['reference' => $referenceId]),
                'qr_string' => $qrString,
                'payment_url' => null,
            ], 'Checkout QRIS dibuat', 201);
        }

        try {
            if ($provider === 'ipaymu') {
                $session = $this->ipaymu()->createRedirectPayment([
                    'product' => [(string) $order->product_name],
                    'qty' => [1],
                    'price' => [(int) $order->amount],
                    'paymentMethod' => $ipaymuMethods,
                    'referenceId' => $referenceId,
                    'description' => ['Pembelian: ' . (string) $order->product_name],
                    'buyerName' => (string) $order->buyer_name,
                    'buyerEmail' => (string) $order->buyer_email,
                    'buyerPhone' => (string) ($order->buyer_phone ?? ''),
                    'returnUrl' => $returnUrl,
                    'cancelUrl' => $returnUrl,
                    'notifyUrl' => $this->ipaymuNotifyUrl([
                        'purpose' => 'landing_sale',
                        'organization_id' => (int) $page->organization_id,
                        'reference_id' => $referenceId,
                    ]),
                ]);
            } elseif ($provider === 'doku') {
                $session = $this->doku()->createCheckout([
                    'order' => [
                        'amount' => (int) $order->amount,
                        'invoice_number' => $referenceId,
                        'currency' => 'IDR',
                        'callback_url' => $returnUrl,
                        'callback_url_result' => $returnUrl,
                        'language' => 'ID',
                        'auto_redirect' => true,
                        'line_items' => [[
                            'name' => (string) $order->product_name,
                            'price' => (int) $order->amount,
                            'quantity' => 1,
                        ]],
                    ],
                    'payment' => [
                        'payment_due_date' => 1440,
                        'payment_method_types' => $this->dokuSettings()->getConfig()['payment_method_types'],
                    ],
                    'customer' => [
                        'name' => (string) $order->buyer_name,
                        'email' => (string) $order->buyer_email,
                    ],
                    'additional_info' => [
                        'override_notification_url' => $this->dokuNotifyUrl(),
                    ],
                ]);
            } else {
                $session = $this->xendit()->createPaymentSession([
                    'reference_id' => $referenceId,
                    'session_type' => 'PAY',
                    'mode' => 'PAYMENT_LINK',
                    'amount' => (int) $order->amount,
                    'currency' => 'IDR',
                    'country' => 'ID',
                    'locale' => 'id',
                    'capture_method' => 'AUTOMATIC',
                    'allow_save_payment_method' => 'DISABLED',
                    'success_return_url' => $returnUrl,
                    'cancel_return_url' => $returnUrl,
                    'description' => 'Pembelian: ' . (string) $order->product_name,
                    'items' => [[
                        'reference_id' => 'landing_sale',
                        'type' => 'DIGITAL_SERVICE',
                        'name' => (string) $order->product_name,
                        'net_unit_amount' => (int) $order->amount,
                        'quantity' => 1,
                        'category' => 'LANDING_SALE',
                    ]],
                    'customer' => [
                        'reference_id' => 'buyer_' . $referenceId,
                        'type' => 'INDIVIDUAL',
                        'email' => (string) $order->buyer_email,
                        'individual_detail' => [
                            'given_names' => (string) Str::of((string) $order->buyer_name)->before(' ')->value(),
                            'surname' => (string) Str::of((string) $order->buyer_name)->after(' ')->value(),
                        ],
                    ],
                    'metadata' => [
                        'purpose' => 'landing_sale',
                        'organization_id' => (int) $page->organization_id,
                        'reference_id' => $referenceId,
                    ],
                ]);
            }
        } catch (\Throwable $exception) {
            $order->forceFill(['status' => LandingPageOrder::STATUS_FAILED])->save();

            return $this->fail($exception->getMessage(), [
                'code' => strtoupper($provider) . '_LANDING_CHECKOUT_FAILED',
            ], 422);
        }

        $paymentUrl = (string) (
            data_get($session, 'payment_link_url')
            ?: data_get($session, 'Data.Url')
            ?: data_get($session, 'Url')
            ?: data_get($session, 'response.payment.url')
            ?: ''
        );

        $order->forceFill([
            'provider' => $provider,
            'gateway_ref' => (string) (
                data_get($session, 'payment_session_id')
                ?: data_get($session, 'Data.SessionID')
                ?: data_get($session, 'Data.TransactionId')
                ?: data_get($session, 'response.order.session_id')
                ?: ''
            ),
        ])->save();

        return $this->ok([
            'reference_id' => $referenceId,
            'provider' => $provider,
            'amount' => (int) $order->amount,
            'product_name' => (string) $order->product_name,
            'payment_url' => $paymentUrl !== '' ? $paymentUrl : null,
        ], 'Checkout produk dibuat', 201);
    }
}
