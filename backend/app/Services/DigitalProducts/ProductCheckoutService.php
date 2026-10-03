<?php

namespace App\Services\DigitalProducts;

use App\Models\DigitalProduct;
use App\Models\ProductPurchase;
use App\Models\User;
use App\Services\Billing\PaymentPolicy;
use App\Services\Hellom\DokuService;
use App\Services\Hellom\DokuSettingsService;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Hellom\ManualPaymentSettingsService;
use App\Services\Hellom\XenditService;
use App\Services\NotificationService;
use App\Services\Payments\ChargeRequest;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\IpaymuPaymentVerifier;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts payment for a platform digital product (manual transfer, iPaymu direct
 * charge, or a hosted gateway checkout). Shared by the logged-in consumer checkout
 * and the public guest checkout.
 *
 * start() returns ['ok' => bool, 'message' => string, 'data' => array] on success or
 * ['ok' => false, 'message' => string, 'code' => string, 'status' => int] on failure.
 */
class ProductCheckoutService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly GatewayRegistry $gateways,
    ) {
    }

    /**
     * @param array{payment_flow?:?string,manual_payment_method?:?string,gateway_channel?:?string} $options
     * @param array{return_url?:?string,buyer_phone?:?string,guest_token_hash?:?string} $context
     * @return array<string,mixed>
     */
    public function start(User $user, DigitalProduct $product, array $options, array $context = []): array
    {
        $validated = $options;
        $notificationService = $this->notificationService;

        $existing = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($existing && $existing->hasAccess()) {
            return $this->success([
                'purchase_id' => $existing->id,
                'status' => $existing->payment_status,
                'payment_gateway' => $existing->payment_gateway,
                'payment_method' => $existing->payment_method,
                'checkout_url' => $existing->checkout_url,
            ], 'Produk sudah dimiliki');
        }

        if ($product->type === 'subscription_locked') {
            return $this->failure('Produk ini hanya untuk pelanggan berlangganan', 'SUBSCRIPTION_REQUIRED', 403);
        }

        if ($product->type === 'free' || (int) $product->price === 0) {
            $purchase = ProductPurchase::query()->create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'transaction_code' => 'FREE-' . strtoupper(Str::random(10)),
                'amount_paid' => 0,
                'payment_status' => 'paid',
                'payment_gateway' => 'free',
                'paid_at' => now(),
            ]);

            $product->increment('total_purchases');

            $notificationService->notifyConsumerAccessActivated($user, null, $product->name);

            return $this->success([
                'purchase_id' => $purchase->id,
                'status' => $purchase->payment_status,
                'payment_gateway' => $purchase->payment_gateway,
                'payment_method' => $purchase->payment_method,
                'checkout_url' => $purchase->checkout_url,
            ], 'Produk berhasil diaktifkan');
        }

        // Which paths are open is decided by the super-admin payment settings (PaymentPolicy).
        $manualOptions = app(ManualPaymentSettingsService::class)->publicOptions();
        $policy = app(PaymentPolicy::class)->checkoutOptions();
        $manualEnabled = $policy['manual'];
        $provider = $policy['provider'];
        $gatewayReady = $policy['gateway'];
        $paymentFlow = (string) ($validated['payment_flow'] ?? '');
        if ($paymentFlow === '') {
            $paymentFlow = $gatewayReady ? 'gateway' : ($manualEnabled ? 'manual' : 'gateway');
        }

        if ($existing && $existing->payment_status === 'pending') {
            if ($paymentFlow === 'gateway' && $existing->checkout_url) {
                $this->applyContext($existing, $context);

                return $this->success([
                    'purchase_id' => $existing->id,
                    'status' => $existing->payment_status,
                    'payment_gateway' => $existing->payment_gateway,
                    'payment_method' => $existing->payment_method,
                    'checkout_url' => $existing->checkout_url,
                ], 'Checkout masih menunggu pembayaran');
            }

            if ($paymentFlow === 'manual' && $existing->payment_gateway === 'manual' && $existing->payment_method) {
                $this->applyContext($existing, $context);

                return $this->success([
                    'purchase_id' => $existing->id,
                    'status' => $existing->payment_status,
                    'payment_gateway' => $existing->payment_gateway,
                    'payment_method' => $existing->payment_method,
                    'checkout_url' => $existing->checkout_url,
                    'manual_payment' => $this->resolveManualMethod($manualOptions, $existing->payment_method),
                    'manual_payment_options' => $manualOptions,
                ], 'Checkout manual masih menunggu konfirmasi');
            }
        }

        if ($paymentFlow === 'manual') {
            if (!$manualEnabled) {
                return $this->failure('Metode pembayaran manual belum diaktifkan.', 'MANUAL_PAYMENT_DISABLED', 422);
            }

            $manualMethod = (string) ($validated['manual_payment_method'] ?? ($existing?->payment_method ?? ''));
            $manualDetail = $this->resolveManualMethod($manualOptions, $manualMethod);
            if (!$manualDetail) {
                return $this->failure('Metode pembayaran manual tidak valid.', 'INVALID_MANUAL_PAYMENT_METHOD', 422);
            }

            $purchase = $this->preparePurchase($user, $product, $existing, $context);
            $purchase->forceFill([
                'payment_status' => 'pending',
                'payment_gateway' => 'manual',
                'payment_method' => $manualMethod,
                'gateway_ref' => null,
                'checkout_url' => null,
                'paid_at' => null,
            ])->save();

            $notificationService->notifyConsumerPaymentPending($user, $purchase, $product->name);
            $notificationService->notifyOwnerNewPayment($user, $purchase, $product);

            return $this->success([
                'purchase_id' => $purchase->id,
                'status' => $purchase->payment_status,
                'payment_gateway' => $purchase->payment_gateway,
                'payment_method' => $purchase->payment_method,
                'checkout_url' => $purchase->checkout_url,
                'manual_payment' => $manualDetail,
                'manual_payment_options' => $manualOptions,
            ], 'Checkout manual siap dikonfirmasi');
        }

        if (!$gatewayReady) {
            return $this->failure(
                $policy['gateway_ready'] ? 'Pembayaran otomatis sedang tidak diaktifkan. Silakan gunakan transfer manual.' : 'Gateway pembayaran belum siap.',
                'PAYMENT_GATEWAY_NOT_READY',
                422
            );
        }

        // In-dashboard direct charge: render VA/QRIS inside Hellom instead of
        // redirecting to the gateway's hosted page. Supported for iPaymu when a
        // channel is chosen; other providers keep the hosted checkout fallback.
        $gatewayChannel = strtolower(trim((string) ($validated['gateway_channel'] ?? '')));
        if ($provider === 'ipaymu' && $gatewayChannel !== '') {
            $purchase = $this->preparePurchase($user, $product, $existing, $context);

            try {
                $instructions = $this->createIpaymuDirectCharge($purchase, $product, $user, $gatewayChannel, $context);
            } catch (\Throwable $exception) {
                return $this->failure($exception->getMessage(), 'PAYMENT_SESSION_FAILED', 422);
            }

            $purchase->forceFill([
                'payment_status' => 'pending',
                'payment_gateway' => 'ipaymu',
                'payment_method' => $gatewayChannel,
                'gateway_ref' => (string) ($instructions['transaction_id'] ?? ''),
                'checkout_url' => null,
                'payment_instructions' => $instructions,
                'paid_at' => null,
            ])->save();

            $notificationService->notifyConsumerPaymentPending($user, $purchase, $product->name);
            $notificationService->notifyOwnerNewPayment($user, $purchase, $product);

            return $this->success([
                'purchase_id' => $purchase->id,
                'status' => $purchase->payment_status,
                'payment_gateway' => $purchase->payment_gateway,
                'payment_method' => $purchase->payment_method,
                'checkout_url' => null,
                'payment_instructions' => $instructions,
            ], 'Instruksi pembayaran siap');
        }

        $purchase = $this->preparePurchase($user, $product, $existing, $context);

        try {
            $session = $this->createGatewayCheckout($provider, $purchase, $product, $user, $context);
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage(), 'PAYMENT_SESSION_FAILED', 422);
        }

        $checkoutUrl = (string) ($session['checkout_url'] ?? '');
        if ($checkoutUrl === '') {
            return $this->failure('Checkout URL tidak tersedia.', 'CHECKOUT_URL_MISSING', 422);
        }

        $purchase->forceFill([
            'payment_status' => 'pending',
            'payment_gateway' => $provider,
            'payment_method' => $provider,
            'gateway_ref' => (string) ($session['gateway_ref'] ?? ''),
            'checkout_url' => $checkoutUrl,
            'paid_at' => null,
        ])->save();

        $notificationService->notifyConsumerPaymentPending($user, $purchase, $product->name);
        $notificationService->notifyOwnerNewPayment($user, $purchase, $product);

        return $this->success([
            'purchase_id' => $purchase->id,
            'status' => $purchase->payment_status,
            'payment_gateway' => $purchase->payment_gateway,
            'payment_method' => $purchase->payment_method,
            'checkout_url' => $purchase->checkout_url,
        ], 'Checkout gateway siap');
    }

    /**
     * Payment options for a public checkout page (no secrets).
     *
     * @return array<string,mixed>
     */
    public function publicPaymentOptions(): array
    {
        $manualOptions = app(ManualPaymentSettingsService::class)->publicOptions();
        $policy = app(PaymentPolicy::class)->checkoutOptions();
        $provider = $policy['provider'];
        $manualEnabled = $policy['manual'];

        return [
            'gateway' => [
                'provider' => $provider,
                'ready' => $policy['gateway'],
                // iPaymu supports rendering VA/QRIS on our page; others redirect to a hosted page.
                'channels' => $provider === 'ipaymu'
                    ? collect(app(IpaymuSettingsService::class)->enabledDirectChannels())->map(fn (array $c, string $key) => ['key' => $key, 'label' => $c[2], 'type' => $c[0]])->values()->all()
                    : [],
            ],
            'manual' => [
                'enabled' => $manualEnabled,
                'notes' => $manualOptions['notes'] ?? null,
                'methods' => $manualEnabled ? array_values($manualOptions['methods']) : [],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $manualOptions
     * @return array<string,mixed>|null
     */
    public function resolveManualMethod(array $manualOptions, string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        $methods = $manualOptions['methods'] ?? [];
        foreach ($methods as $method) {
            if (is_array($method) && (string) ($method['key'] ?? '') === $key) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Best-effort active confirmation for a pending iPaymu purchase. Only ever
     * upgrades to paid; the webhook remains responsible for notifications.
     */
    public function syncIpaymuPurchaseStatus(ProductPurchase $purchase): void
    {
        if ($purchase->payment_status === 'paid' || (string) $purchase->gateway_ref === '') {
            return;
        }

        try {
            // gateway_ref was stored by us when the charge was created: trusted id, but
            // iPaymu must still confirm the amount and that the payment is ours.
            $check = app(IpaymuPaymentVerifier::class)->verify((string) $purchase->transaction_code, (int) $purchase->amount_paid, (string) $purchase->gateway_ref, true);
            if (!$check['ok']) {
                return;
            }

            DB::transaction(function () use ($purchase): void {
                $locked = ProductPurchase::query()->lockForUpdate()->find((int) $purchase->id);
                if (!$locked instanceof ProductPurchase || $locked->payment_status === 'paid') {
                    return;
                }
                $locked->forceFill(['payment_status' => 'paid', 'paid_at' => now()])->save();
                $locked->product?->increment('total_purchases');
                $purchase->setRawAttributes($locked->getAttributes(), true);
            }, 3);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function success(array $data, string $message): array
    {
        return ['ok' => true, 'message' => $message, 'data' => $data];
    }

    /**
     * @return array<string,mixed>
     */
    private function failure(string $message, string $code, int $status): array
    {
        return ['ok' => false, 'message' => $message, 'code' => $code, 'status' => $status];
    }

    /**
     * Guest checkouts carry a status-page token (and optional phone); logged-in
     * checkouts clear any token left by an earlier guest attempt.
     *
     * @param array<string,mixed> $context
     */
    private function applyContext(ProductPurchase $purchase, array $context): void
    {
        $purchase->forceFill([
            'guest_token_hash' => $context['guest_token_hash'] ?? null,
            'buyer_phone' => $context['buyer_phone'] ?? $purchase->buyer_phone,
        ])->save();
    }

    /**
     * @param array<string,mixed> $context
     */
    private function preparePurchase(User $user, DigitalProduct $product, ?ProductPurchase $existing, array $context): ProductPurchase
    {
        if ($existing instanceof ProductPurchase) {
            $existing->forceFill([
                'amount_paid' => (int) $product->price,
                'transaction_code' => $existing->transaction_code ?: 'PUR-' . strtoupper(Str::random(10)),
                'payment_status' => 'pending',
                'paid_at' => null,
            ])->save();
            $this->applyContext($existing, $context);

            return $existing;
        }

        $purchase = ProductPurchase::query()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'transaction_code' => 'PUR-' . strtoupper(Str::random(10)),
            'amount_paid' => (int) $product->price,
            'payment_status' => 'pending',
            'payment_gateway' => null,
        ]);
        $this->applyContext($purchase, $context);

        return $purchase;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function returnUrl(DigitalProduct $product, array $context): string
    {
        $url = (string) ($context['return_url'] ?? '');

        return $url !== '' ? $url : FrontendUrl::to("/dashboard/products/{$product->slug}");
    }

    /**
     * @param array<string,mixed> $context
     * @return array{checkout_url:string,gateway_ref?:string}
     */
    private function createGatewayCheckout(string $provider, ProductPurchase $purchase, DigitalProduct $product, User $user, array $context): array
    {
        $returnUrl = $this->returnUrl($product, $context);

        if ($provider === 'ipaymu') {
            // iPaymu's hosted page, through the shared adapter (one request builder/parser).
            $charge = $this->gateways->get('ipaymu')->createCharge($this->ipaymuChargeRequest($purchase, $product, $user, $context, 'other', $returnUrl));

            return [
                'checkout_url' => (string) ($charge->paymentUrl ?? ''),
                'gateway_ref' => (string) ($charge->sessionId ?: $charge->transactionId ?: ''),
            ];
        }

        if ($provider === 'doku') {
            $session = app(DokuService::class)->createCheckout([
                'order' => [
                    'amount' => (int) $product->price,
                    'invoice_number' => (string) $purchase->transaction_code,
                    'currency' => 'IDR',
                    'callback_url' => $returnUrl,
                    'callback_url_result' => $returnUrl,
                    'language' => 'ID',
                    'auto_redirect' => false,
                    'line_items' => [
                        [
                            'name' => (string) $product->name,
                            'price' => (int) $product->price,
                            'quantity' => 1,
                        ],
                    ],
                    'additional_info' => [
                        'purpose' => 'product_purchase',
                        'purchase_id' => (int) $purchase->id,
                        'product_id' => (int) $product->id,
                        'user_id' => (int) $user->id,
                    ],
                ],
                'payment' => [
                    'payment_due_date' => 1440,
                    'payment_method_types' => app(DokuSettingsService::class)->getConfig()['payment_method_types'],
                ],
                'customer' => [
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                ],
                'additional_info' => [
                    'override_notification_url' => $this->dokuNotifyUrl(),
                ],
            ]);

            return [
                'checkout_url' => (string) data_get($session, 'response.payment.url', ''),
                'gateway_ref' => (string) data_get($session, 'response.order.session_id', ''),
            ];
        }

        $session = app(XenditService::class)->createPaymentSession([
            'reference_id' => (string) $purchase->transaction_code,
            'session_type' => 'PAY',
            'mode' => 'PAYMENT_LINK',
            'amount' => (int) $product->price,
            'currency' => 'IDR',
            'country' => 'ID',
            'locale' => 'id',
            'capture_method' => 'AUTOMATIC',
            'allow_save_payment_method' => 'DISABLED',
            'success_return_url' => $returnUrl,
            'cancel_return_url' => $returnUrl,
            'description' => "Pembelian {$product->name}",
            'items' => [
                [
                    'reference_id' => (string) $product->id,
                    'type' => 'DIGITAL_PRODUCT',
                    'name' => (string) $product->name,
                    'net_unit_amount' => (int) $product->price,
                    'quantity' => 1,
                    'category' => 'DIGITAL',
                ],
            ],
            'customer' => [
                'reference_id' => $this->buildCustomerReferenceId($user),
                'type' => 'INDIVIDUAL',
                'email' => (string) $user->email,
                'individual_detail' => [
                    'given_names' => (string) Str::of((string) $user->name)->before(' ')->value(),
                    'surname' => (string) Str::of((string) $user->name)->after(' ')->value(),
                ],
            ],
            'metadata' => [
                'purpose' => 'product_purchase',
                'purchase_id' => (int) $purchase->id,
                'product_id' => (int) $product->id,
                'user_id' => (int) $user->id,
            ],
        ]);

        return [
            'checkout_url' => (string) data_get($session, 'payment_link_url', ''),
            'gateway_ref' => (string) data_get($session, 'payment_session_id', ''),
        ];
    }

    private function dokuNotifyUrl(): string
    {
        $token = (string) app(DokuSettingsService::class)->getConfig()['callback_token'];
        if ($token === '') {
            return url('/api/v1/hellom/webhooks/doku');
        }

        return url('/api/v1/hellom/webhooks/doku?token=' . urlencode($token));
    }

    private function buildCustomerReferenceId(User $user): string
    {
        return 'consumer_' . (int) $user->id;
    }

    /**
     * The iPaymu charge for a digital product purchase. Request building, signed notify
     * URL and response parsing live in IpaymuGateway (shared with Hellom Page shops).
     *
     * @param array<string,mixed> $context
     */
    private function ipaymuChargeRequest(ProductPurchase $purchase, DigitalProduct $product, User $user, array $context, string $method, ?string $returnUrl = null): ChargeRequest
    {
        return new ChargeRequest(
            reference: (string) $purchase->transaction_code,
            amount: (int) $product->price,
            productName: (string) $product->name,
            buyerName: (string) ($user->name ?: 'Pelanggan Hellom'),
            buyerEmail: (string) $user->email,
            buyerPhone: $this->resolveBuyerPhone($user, $context),
            returnUrl: $returnUrl ?? $this->returnUrl($product, $context),
            notifyContext: [
                'purpose' => 'product_purchase',
                'purchase_id' => (int) $purchase->id,
                'product_id' => (int) $product->id,
                'user_id' => (int) $user->id,
                'reference_id' => (string) $purchase->transaction_code,
            ],
            preferredMethod: $method,
            description: "Pembelian {$product->name}",
        );
    }

    /**
     * VA number / QRIS shown on our page (payment instructions for the purchase page).
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function createIpaymuDirectCharge(ProductPurchase $purchase, DigitalProduct $product, User $user, string $channelKey, array $context): array
    {
        $channels = app(IpaymuSettingsService::class)->enabledDirectChannels();
        if (!isset($channels[$channelKey])) {
            throw new \RuntimeException('Metode pembayaran ini sedang tidak tersedia. Silakan pilih metode lain.');
        }
        [$method, $channel] = $channels[$channelKey];

        $charge = $this->gateways->get('ipaymu')->createCharge($this->ipaymuChargeRequest($purchase, $product, $user, $context, $channelKey));

        return array_filter([
            'provider' => 'ipaymu',
            'method' => $method,
            'channel' => $channel,
            'channel_label' => $charge->channelLabel,
            'va_number' => $charge->vaNumber,
            'qr_string' => $charge->qrString,
            'qr_image_url' => $charge->qrImageUrl,
            'amount' => $charge->total ?: (int) $product->price,
            'fee' => (int) ($charge->fee ?? 0),
            'expires_at' => $charge->expiresAt,
            'reference_id' => (string) $purchase->transaction_code,
            'transaction_id' => (string) ($charge->transactionId ?? ''),
            'session_id' => (string) ($charge->sessionId ?? ''),
        ], static fn ($value) => $value !== '' && $value !== null);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function resolveBuyerPhone(User $user, array $context = []): string
    {
        $phone = preg_replace('/\D/', '', (string) ($context['buyer_phone'] ?? $user->phone ?? ''));

        return is_string($phone) && $phone !== '' ? $phone : '081234567890';
    }
}
