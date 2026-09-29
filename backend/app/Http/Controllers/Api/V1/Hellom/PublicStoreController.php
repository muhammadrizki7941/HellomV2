<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Jobs\SendLandingSaleEmails;
use App\Models\LandingPageOrder;
use App\Models\LandingProduct;
use App\Models\LandingReport;
use App\Models\LandingTrackingSetting;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Services\Landing\CheckoutService;
use App\Services\Landing\LandingShop;
use App\Services\Landing\LandingStats;
use App\Services\Landing\OrderAccessService;
use App\Services\Landing\PaymentStarter;
use App\Services\Landing\SellerTrust;
use App\Services\Security\CheckoutCaptcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public (no login) side of Hellom Page selling: checkout page (/beli/{id}), buyer
 * access page (/akses/{token}), "Cek pesanan saya", and "Laporkan".
 */
class PublicStoreController extends BaseApiController
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly OrderAccessService $access,
        private readonly SellerTrust $trust,
    ) {
    }

    public function product(string $publicId, PaymentStarter $payments): JsonResponse
    {
        [$product, $organization] = $this->findProduct($publicId);
        if (!$product) {
            return $this->fail('Produk tidak ditemukan', ['code' => 'PRODUCT_NOT_FOUND'], 404);
        }
        if ($organization->landing_suspended_at !== null) {
            return $this->fail('Toko ini sedang nonaktif', ['code' => 'SELLER_SUSPENDED'], 410);
        }

        return $this->ok([
            'product' => $product->publicPayload(),
            'seller' => $this->trust->publicSeller($organization),
            'payment_options' => $payments->options(),
            'min_total' => CheckoutService::MIN_TOTAL,
            'tracking' => LandingTrackingSetting::query()->find($organization->id)?->publicIds() ?? [],
        ], 'Produk');
    }

    public function quote(Request $request, string $publicId): JsonResponse
    {
        [$product] = $this->findProduct($publicId);
        if (!$product) {
            return $this->fail('Produk tidak ditemukan', ['code' => 'PRODUCT_NOT_FOUND'], 404);
        }
        $validated = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);

        return $this->ok($this->checkout->quote($product, (int) ($validated['quantity'] ?? 1), $validated['coupon_code'] ?? null), 'Rincian harga');
    }

    public function checkout(Request $request, string $publicId, PaymentStarter $payments, CheckoutCaptcha $captcha): JsonResponse
    {
        [$product, $organization] = $this->findProduct($publicId);
        if (!$product) {
            return $this->fail('Produk tidak ditemukan', ['code' => 'PRODUCT_NOT_FOUND'], 404);
        }
        if ($organization->landing_suspended_at !== null) {
            return $this->fail('Toko ini sedang nonaktif', ['code' => 'SELLER_SUSPENDED'], 410);
        }
        if ($payments->options() === []) {
            return $this->fail('Pembayaran sedang tidak tersedia. Silakan coba lagi nanti.', ['code' => 'GATEWAY_NOT_READY'], 422);
        }
        $validated = $request->validate($this->checkout->rules($product));
        // Repeated checkouts from one IP: Turnstile (only when keys are configured).
        if ($captcha->required($request) && !$captcha->verify($request->input('captcha_token'), $request)) {
            return $this->fail('Satu langkah lagi: centang verifikasi di bawah lalu tekan Bayar.', [
                'code' => 'CAPTCHA_REQUIRED',
                'site_key' => $captcha->siteKey(),
            ], 422);
        }

        $order = $this->checkout->createOrder($product, $validated);
        $captcha->recordCheckout($request);
        try {
            $payment = $payments->start($order, $validated['payment_method'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), ['code' => 'LANDING_CHECKOUT_FAILED'], 422);
        }

        return $this->ok($payment, 'Pesanan dibuat', 201);
    }

    public function access(string $token): JsonResponse
    {
        $order = $this->access->find($token);
        if (!$order) {
            return $this->fail('Akses tidak ditemukan. Cek lagi tautan di email kamu.', ['code' => 'ACCESS_NOT_FOUND'], 404);
        }

        return $this->ok($this->access->payload($order), 'Akses produk')->header('Cache-Control', 'no-store, private');
    }

    public function open(string $token): JsonResponse
    {
        $order = $this->access->find($token);
        if (!$order) {
            return $this->fail('Akses tidak ditemukan', ['code' => 'ACCESS_NOT_FOUND'], 404);
        }
        $url = $this->access->open($order);

        return $this->ok(['url' => $url], 'Membuka produk')->header('Cache-Control', 'no-store, private');
    }

    /** Signed, short-lived URL from open(): streams the private file. */
    public function download(Request $request, string $token): StreamedResponse|JsonResponse
    {
        if (!$request->hasValidSignature()) {
            return $this->fail('Tautan unduhan sudah kedaluwarsa. Buka lagi dari halaman akses.', ['code' => 'DOWNLOAD_LINK_EXPIRED'], 403);
        }
        $order = $this->access->find($token);
        if (!$order) {
            return $this->fail('Akses tidak ditemukan', ['code' => 'ACCESS_NOT_FOUND'], 404);
        }
        try {
            [$path, $name] = $this->access->takeDownload($order);
        } catch (ValidationException $e) {
            // Opened in the browser (no JSON Accept header): answer plainly, no redirect.
            return $this->fail(collect($e->errors())->flatten()->first() ?? 'Unduhan tidak tersedia', ['code' => 'DOWNLOAD_NOT_AVAILABLE'], 422);
        }

        return Storage::disk('local')->download($path, $name, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** "Kirim ulang email" from the access page. */
    public function resend(string $token): JsonResponse
    {
        $order = $this->access->find($token);
        if (!$order) {
            return $this->fail('Akses tidak ditemukan', ['code' => 'ACCESS_NOT_FOUND'], 404);
        }
        if (!$this->allowResend($order)) {
            return $this->fail('Email baru saja dikirim. Coba lagi beberapa menit lagi.', ['code' => 'RESEND_TOO_SOON'], 429);
        }
        SendLandingSaleEmails::dispatch((int) $order->id, true);

        return $this->ok(['sent' => true], 'Email dikirim ulang ke ' . $this->maskEmail((string) $order->buyer_email));
    }

    /**
     * "Cek pesanan saya": email + order number. The access link is only ever sent to the
     * buyer's email; the answer is the same whether or not the order exists.
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:150'],
            'reference' => ['required', 'string', 'max:40'],
        ]);
        $order = LandingPageOrder::query()->where('reference_id', trim($validated['reference']))->first();
        if ($order && strtolower((string) $order->buyer_email) === strtolower(trim($validated['email'])) && $order->isPaid() && $this->allowResend($order)) {
            SendLandingSaleEmails::dispatch((int) $order->id, true);
        }

        return $this->ok(['sent' => true], 'Kalau data cocok dengan pesanan lunas, link akses sudah kami kirim ke email tersebut.');
    }

    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_slug' => ['nullable', 'string', 'max:120'],
            'product_id' => ['nullable', 'string', 'max:24'],
            'landing_page_id' => ['nullable', 'integer'],
            'reason' => ['required', 'in:' . implode(',', array_keys(LandingReport::REASONS))],
            'description' => ['nullable', 'string', 'max:2000'],
            'reporter_email' => ['nullable', 'email:rfc', 'max:150'],
            'page_url' => ['nullable', 'string', 'max:500'],
        ]);
        $organization = !empty($validated['organization_slug']) ? Organization::query()->where('slug', $validated['organization_slug'])->first() : null;
        $product = !empty($validated['product_id']) ? LandingProduct::query()->where('public_id', $validated['product_id'])->first() : null;
        $pageId = !empty($validated['landing_page_id']) ? OrganizationLandingPage::query()->whereKey($validated['landing_page_id'])->value('id') : null;
        if (!$organization && !$product) {
            return $this->fail('Halaman yang dilaporkan tidak ditemukan', ['code' => 'REPORT_TARGET_NOT_FOUND'], 404);
        }

        LandingReport::query()->create([
            'organization_id' => $product?->organization_id ?? $organization?->id,
            'landing_page_id' => $pageId,
            'product_id' => $product?->id,
            'reason' => $validated['reason'],
            'description' => $validated['description'] ?? null,
            'reporter_email' => $validated['reporter_email'] ?? null,
            'reporter_ip' => $request->ip(),
            'page_url' => $validated['page_url'] ?? null,
            'status' => 'open',
        ]);

        return $this->ok(['received' => true], 'Terima kasih, laporan kamu sudah kami terima dan akan ditinjau tim Hellom.', 201);
    }

    /** Stats beacon from public pages (visit, product view, click, checkout start). */
    public function event(Request $request, LandingShop $shop, LandingStats $stats): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:40'],
            'metric' => ['required', 'in:' . implode(',', LandingStats::METRICS)],
            'page_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'string', 'max:24'],
            'dimension' => ['nullable', 'string', 'max:200'],
            'source' => ['nullable', 'string', 'max:200'],
        ]);
        $organization = $shop->findByUsername($validated['username']);
        if (!$organization) {
            return $this->ok(['recorded' => false], 'Diabaikan');
        }
        $pageId = !empty($validated['page_id'])
            ? OrganizationLandingPage::query()->where('organization_id', $organization->id)->whereKey($validated['page_id'])->value('id') : null;
        $productId = !empty($validated['product_id'])
            ? LandingProduct::query()->where('organization_id', $organization->id)->where('public_id', $validated['product_id'])->value('id') : null;
        $dimension = $validated['metric'] === 'visit' ? LandingStats::sourceLabel($validated['source'] ?? '') : (string) ($validated['dimension'] ?? '');
        $stats->record((int) $organization->id, $validated['metric'], $pageId, $productId, $dimension, $request->ip() . '|' . $request->userAgent());

        return $this->ok(['recorded' => true], 'OK');
    }

    /** QR code (SVG) of a Hellom Page address, for the "Unduh QR" button. */
    public function qr(Request $request): Response|JsonResponse
    {
        $url = (string) $request->query('url', '');
        $host = parse_url($url, PHP_URL_HOST);
        $allowed = array_filter([parse_url((string) config('app.frontend_url'), PHP_URL_HOST), parse_url((string) config('app.url'), PHP_URL_HOST), $request->getHost()]);
        if (!in_array($host, $allowed, true) || strlen($url) > 300) {
            return $this->fail('Hanya link Hellom yang bisa dibuat QR', ['code' => 'QR_URL_NOT_ALLOWED'], 422);
        }
        $svg = QrCode::format('svg')->size(512)->margin(1)->errorCorrection('M')->generate($url);

        return response((string) $svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'"]);
    }

    /**
     * Browser "Purchase" event for the seller's pixels, handed out once per order: the
     * thank-you page asks after the payment is confirmed; a refresh gets fire=false. The
     * same event_id is used by the server-side Conversions API (deduplication).
     */
    public function purchaseEvent(string $reference): JsonResponse
    {
        $order = LandingPageOrder::query()->where('reference_id', $reference)->first();
        if (!$order || !$order->isPaid()) {
            return $this->ok(['fire' => false], 'Belum lunas');
        }
        $claimed = LandingPageOrder::query()->whereKey($order->id)->whereNull('purchase_tracked_at')->update(['purchase_tracked_at' => now()]);
        if ($claimed === 0) {
            return $this->ok(['fire' => false], 'Sudah dikirim');
        }
        $tracking = LandingTrackingSetting::query()->find($order->organization_id)?->publicIds() ?? [];

        return $this->ok([
            'fire' => $tracking !== [],
            'event_id' => 'purchase_' . $order->reference_id,
            'value' => (int) $order->amount,
            'currency' => 'IDR',
            'content_ids' => array_filter([LandingProduct::withTrashed()->whereKey($order->product_id)->value('public_id')]),
            'content_name' => (string) $order->product_name,
            'tracking' => $tracking,
            'username' => Organization::query()->find($order->organization_id)?->landingUsername(),
        ], 'Purchase');
    }

    /** @return array{0: ?LandingProduct, 1: ?Organization} */
    private function findProduct(string $publicId): array
    {
        $product = LandingProduct::query()->where('public_id', $publicId)->first();
        $organization = $product ? Organization::query()->find($product->organization_id) : null;

        return [$product && $organization ? $product : null, $organization];
    }

    private function allowResend(LandingPageOrder $order): bool
    {
        return $this->access->allowResend($order);
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return 'email kamu';
        }
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 2) . str_repeat('*', max(1, mb_strlen($name) - 2)) . '@' . $domain;
    }
}
