<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Jobs\SendLandingSaleEmails;
use App\Models\LandingPageOrder;
use App\Models\LandingProduct;
use App\Models\LandingReport;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Services\Landing\CheckoutService;
use App\Services\Landing\OrderAccessService;
use App\Services\Landing\PaymentStarter;
use App\Services\Landing\SellerTrust;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function checkout(Request $request, string $publicId, PaymentStarter $payments): JsonResponse
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

        $order = $this->checkout->createOrder($product, $validated);
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
