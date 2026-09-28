<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Models\DigitalProduct;
use App\Models\ProductPurchase;
use App\Models\User;
use App\Services\DigitalProducts\ProductAccessMailer;
use App\Services\DigitalProducts\ProductCheckoutService;
use App\Services\Hellom\ManualPaymentSettingsService;
use App\Services\Hellom\PaymentGatewaySettingsService;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Checkout for platform digital products without logging in. The buyer gives an
 * email (required) and phone (optional); the purchase is attached to the account
 * with that email, created on the fly when needed. Access is delivered by email
 * once paid (see ProductAccessMailer). The buyer follows progress on a status page
 * identified by a random token.
 */
class GuestProductCheckoutController extends BaseApiController
{
    public function options(string $slug, ProductCheckoutService $checkout): JsonResponse
    {
        $product = $this->findProduct($slug);

        return $this->ok([
            'product' => $this->productSummary($product),
            'guest_checkout_available' => $this->guestCheckoutAllowed($product),
            'payment' => $checkout->publicPaymentOptions(),
        ], 'Guest checkout options');
    }

    public function store(Request $request, string $slug, ProductCheckoutService $checkout): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{8,20}$/'],
            'payment_flow' => ['nullable', 'in:manual,gateway'],
            'manual_payment_method' => ['nullable', 'string', 'max:50'],
            'gateway_channel' => ['nullable', 'string', 'max:30'],
        ]);

        $product = $this->findProduct($slug);
        if (!$this->guestCheckoutAllowed($product)) {
            return $this->fail('Produk ini tidak bisa dibeli tanpa login.', ['code' => 'GUEST_CHECKOUT_UNAVAILABLE'], 422);
        }

        $email = strtolower(trim((string) $validated['email']));
        $phone = isset($validated['phone']) ? preg_replace('/[^0-9+]/', '', (string) $validated['phone']) : null;

        $user = User::query()->where('email', $email)->first();
        if ($user instanceof User) {
            if ($user->isSuspended()) {
                return $this->fail('Email ini tidak dapat digunakan untuk checkout.', ['code' => 'EMAIL_NOT_ALLOWED'], 403);
            }

            $owned = ProductPurchase::query()
                ->where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->first();
            if ($owned?->hasAccess()) {
                return $this->fail(
                    'Email ini sudah memiliki produk ini. Silakan masuk untuk membukanya.',
                    ['code' => 'ALREADY_OWNED'],
                    409
                );
            }
        } else {
            $user = User::query()->create([
                'name' => $this->nameFromEmail($email),
                'email' => $email,
                // Unusable until payment succeeds; the real password is emailed then.
                'password' => Hash::make(Str::random(40)),
                'role' => 'member',
            ]);
            $user->forceFill(['pending_guest_credentials' => true])->save();
        }

        $token = Str::random(48);

        $result = $checkout->start($user, $product, [
            'payment_flow' => $validated['payment_flow'] ?? null,
            'manual_payment_method' => $validated['manual_payment_method'] ?? null,
            'gateway_channel' => $validated['gateway_channel'] ?? null,
        ], [
            'guest_token_hash' => hash('sha256', $token),
            'buyer_phone' => $phone ?: null,
            'return_url' => FrontendUrl::to("/produk/checkout/{$token}"),
        ]);

        if (!$result['ok']) {
            return $this->fail($result['message'], ['code' => $result['code']], $result['status']);
        }

        return $this->ok([
            ...$result['data'],
            'checkout_token' => $token,
        ], $result['message'], 201);
    }

    public function status(string $token, ProductCheckoutService $checkout): JsonResponse
    {
        $purchase = $this->findPurchase($token);
        if (!$purchase) {
            return $this->fail('Transaksi tidak ditemukan.', ['code' => 'CHECKOUT_NOT_FOUND'], 404);
        }

        // Webhook is the source of truth; confirm pending iPaymu charges actively so
        // the page updates even when the webhook lags.
        if ($purchase->payment_status === 'pending'
            && $purchase->payment_gateway === 'ipaymu'
            && (string) $purchase->gateway_ref !== ''
        ) {
            $checkout->syncIpaymuPurchaseStatus($purchase);
            $purchase->refresh();
        }

        $isPending = $purchase->payment_status === 'pending';
        $manualDetail = null;
        if ($isPending && $purchase->payment_gateway === 'manual') {
            $manualDetail = $checkout->resolveManualMethod(
                app(ManualPaymentSettingsService::class)->publicOptions(),
                (string) $purchase->payment_method
            );
        }

        return $this->ok([
            'transaction_code' => $purchase->transaction_code,
            'status' => $purchase->payment_status,
            'is_paid' => $purchase->hasAccess(),
            'amount' => (int) $purchase->amount_paid,
            'payment_gateway' => $purchase->payment_gateway,
            'payment_method' => $purchase->payment_method,
            'checkout_url' => $isPending ? $purchase->checkout_url : null,
            'payment_instructions' => $isPending ? $purchase->payment_instructions : null,
            'manual_payment' => $manualDetail,
            'email' => $this->maskEmail((string) $purchase->user?->email),
            'access_email_sent' => $purchase->access_email_sent_at !== null,
            'product' => $purchase->product ? $this->productSummary($purchase->product) : null,
        ], 'Guest checkout status');
    }

    public function resendAccess(string $token, ProductAccessMailer $mailer): JsonResponse
    {
        $purchase = $this->findPurchase($token);
        if (!$purchase) {
            return $this->fail('Transaksi tidak ditemukan.', ['code' => 'CHECKOUT_NOT_FOUND'], 404);
        }

        if (!$purchase->hasAccess()) {
            return $this->fail('Pembayaran belum diterima.', ['code' => 'PURCHASE_NOT_PAID'], 422);
        }

        if (!$mailer->resend($purchase)) {
            return $this->fail('Email belum berhasil dikirim. Coba lagi beberapa saat lagi.', ['code' => 'EMAIL_NOT_SENT'], 503);
        }

        return $this->ok([
            'email' => $this->maskEmail((string) $purchase->user?->email),
        ], 'Email akses dikirim ulang');
    }

    private function findProduct(string $slug): DigitalProduct
    {
        return DigitalProduct::query()->published()->where('slug', $slug)->firstOrFail();
    }

    private function findPurchase(string $token): ?ProductPurchase
    {
        if (strlen($token) < 32 || strlen($token) > 128) {
            return null;
        }

        return ProductPurchase::query()
            ->with(['product', 'user'])
            ->where('guest_token_hash', hash('sha256', $token))
            ->first();
    }

    /**
     * Paid, one-time products only, and only while the super admin keeps guest checkout
     * on (Admin → Settings → Payment). Free and subscription-only products keep the login flow.
     */
    private function guestCheckoutAllowed(DigitalProduct $product): bool
    {
        return (bool) app(PaymentGatewaySettingsService::class)->getRuntimeConfig()['guest_checkout_enabled']
            && $product->type !== 'free'
            && $product->type !== 'subscription_locked'
            && (int) $product->price > 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function productSummary(DigitalProduct $product): array
    {
        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'name' => $product->name,
            'tagline' => $product->tagline,
            'category' => $product->category,
            'type' => $product->type,
            'price' => (int) $product->price,
            'currency' => $product->currency,
            'thumbnail_url' => $product->thumbnail_url,
        ];
    }

    private function nameFromEmail(string $email): string
    {
        $local = Str::before($email, '@');
        $name = Str::of($local)->replaceMatches('/[._\-+0-9]+/', ' ')->squish()->title()->value();

        return $name !== '' ? Str::limit($name, 60, '') : 'Pelanggan';
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '';
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible . str_repeat('*', max(3, mb_strlen($local) - 2)) . '@' . $domain;
    }
}
