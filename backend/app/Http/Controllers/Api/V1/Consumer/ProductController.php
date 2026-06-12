<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Models\DigitalProduct;
use App\Models\DigitalProductDoc;
use App\Models\DigitalProductFile;
use App\Models\ProductPurchase;
use App\Models\User;
use App\Services\Hellom\DokuService;
use App\Services\Hellom\DokuSettingsService;
use App\Services\Hellom\IpaymuService;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Hellom\ManualPaymentSettingsService;
use App\Services\Hellom\PaymentGatewaySettingsService;
use App\Services\Hellom\XenditService;
use App\Services\Hellom\XenditSettingsService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $products = DigitalProduct::query()
            ->published()
            ->with(['files:id,product_id,label,file_type,version,is_primary'])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->get();

        $purchases = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->whereIn('product_id', $products->pluck('id'))
            ->get()
            ->keyBy('product_id');

        $data = $products->map(function (DigitalProduct $product) use ($purchases) {
            $purchase = $purchases->get($product->id);
            return [
                ...$product->toArray(),
                'is_purchased' => $purchase?->hasAccess() ?? false,
                'purchase' => $purchase,
            ];
        });

        return $this->ok($data, 'Consumer products');
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $product = DigitalProduct::query()
            ->published()
            ->where('slug', $slug)
            ->with(['files', 'docs'])
            ->firstOrFail();

        $purchase = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        $product->files->each->makeHidden(['file_path']);

        if (!$purchase || !$purchase->hasAccess()) {
            $product->setRelation('docs', collect());
        } else {
            $product->docs->each->makeHidden(['file_path']);
        }

        return $this->ok([
            'product' => $product,
            'purchase' => $purchase,
            'is_purchased' => $purchase?->hasAccess() ?? false,
        ], 'Consumer product detail');
    }

    public function purchase(Request $request, string $id, NotificationService $notificationService): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $validated = $request->validate([
            'payment_flow' => ['nullable', 'in:manual,gateway'],
            'manual_payment_method' => ['nullable', 'string', 'max:50'],
        ]);

        $product = DigitalProduct::query()->published()->findOrFail($id);

        $existing = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($existing && $existing->hasAccess()) {
            return $this->ok([
                'purchase_id' => $existing->id,
                'status' => $existing->payment_status,
                'payment_gateway' => $existing->payment_gateway,
                'payment_method' => $existing->payment_method,
                'checkout_url' => $existing->checkout_url,
            ], 'Produk sudah dimiliki');
        }

        if ($product->type === 'subscription_locked') {
            return $this->fail('Produk ini hanya untuk pelanggan berlangganan', ['code' => 'SUBSCRIPTION_REQUIRED'], 403);
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

            return $this->ok([
                'purchase_id' => $purchase->id,
                'status' => $purchase->payment_status,
                'payment_gateway' => $purchase->payment_gateway,
                'payment_method' => $purchase->payment_method,
                'checkout_url' => $purchase->checkout_url,
            ], 'Produk berhasil diaktifkan');
        }

        $manualOptions = app(ManualPaymentSettingsService::class)->publicOptions();
        $runtime = $this->runtimeConfig();
        $manualConfirmationEnabled = (string) ($runtime['checkout_mode'] ?? 'gateway_automatic') === 'manual_confirmation';
        $manualEnabled = $manualConfirmationEnabled
            && (bool) $manualOptions['enabled']
            && count($manualOptions['methods']) > 0;
        $provider = (string) ($runtime['active_provider'] ?? 'xendit');
        $gatewayReady = $this->isGatewayReady($provider);
        $paymentFlow = (string) ($validated['payment_flow'] ?? '');
        if ($paymentFlow === '') {
            $paymentFlow = $gatewayReady ? 'gateway' : ($manualEnabled ? 'manual' : 'gateway');
        }

        if ($existing && $existing->payment_status === 'pending') {
            if ($paymentFlow === 'gateway' && $existing->checkout_url) {
                return $this->ok([
                    'purchase_id' => $existing->id,
                    'status' => $existing->payment_status,
                    'payment_gateway' => $existing->payment_gateway,
                    'payment_method' => $existing->payment_method,
                    'checkout_url' => $existing->checkout_url,
                ], 'Checkout masih menunggu pembayaran');
            }

            if ($paymentFlow === 'manual' && $existing->payment_gateway === 'manual' && $existing->payment_method) {
                return $this->ok([
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
                return $this->fail('Metode pembayaran manual belum diaktifkan.', ['code' => 'MANUAL_PAYMENT_DISABLED'], 422);
            }

            $manualMethod = (string) ($validated['manual_payment_method'] ?? ($existing?->payment_method ?? ''));
            $manualDetail = $this->resolveManualMethod($manualOptions, $manualMethod);
            if (!$manualDetail) {
                return $this->fail('Metode pembayaran manual tidak valid.', ['code' => 'INVALID_MANUAL_PAYMENT_METHOD'], 422);
            }

            $purchase = $this->preparePurchase($user, $product, $existing);
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

            return $this->ok([
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
            return $this->fail('Gateway pembayaran belum siap.', ['code' => 'PAYMENT_GATEWAY_NOT_READY'], 422);
        }

        $purchase = $this->preparePurchase($user, $product, $existing);

        try {
            $session = $this->createGatewayCheckout($provider, $purchase, $product, $user);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage(), ['code' => 'PAYMENT_SESSION_FAILED'], 422);
        }

        $checkoutUrl = (string) ($session['checkout_url'] ?? '');
        if ($checkoutUrl === '') {
            return $this->fail('Checkout URL tidak tersedia.', ['code' => 'CHECKOUT_URL_MISSING'], 422);
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

        return $this->ok([
            'purchase_id' => $purchase->id,
            'status' => $purchase->payment_status,
            'payment_gateway' => $purchase->payment_gateway,
            'payment_method' => $purchase->payment_method,
            'checkout_url' => $purchase->checkout_url,
        ], 'Checkout gateway siap');
    }

    public function download(Request $request, string $id, string $fileId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $purchase = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->where('product_id', $id)
            ->first();

        if (!$purchase || !$purchase->hasAccess()) {
            return $this->fail('Akses ditolak', ['code' => 'FORBIDDEN'], 403);
        }

        $file = DigitalProductFile::query()
            ->where('id', $fileId)
            ->where('product_id', $id)
            ->firstOrFail();

        $disk = Storage::disk('local');
        if (!method_exists($disk, 'temporaryUrl') && !$disk->providesTemporaryUrls()) {
            return $this->fail('Download URL tidak tersedia', ['code' => 'TEMP_URL_UNSUPPORTED'], 422);
        }

        $url = $disk->temporaryUrl($file->file_path, now()->addMinutes(5));

        $purchase->forceFill([
            'download_count' => $purchase->download_count + 1,
            'last_downloaded_at' => now(),
        ])->save();

        $purchase->product?->increment('total_downloads');

        return $this->ok([
            'download_url' => $url,
            'expires_in' => 300,
        ], 'Download URL generated');
    }

    public function myPurchases(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $purchases = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->with('product')
            ->orderByDesc('created_at')
            ->get();

        return $this->ok($purchases, 'My purchases');
    }

    public function previewDoc(Request $request, string $id, string $docId): Response
    {
        $user = $request->user();
        if (!$user instanceof User) {
            abort(401);
        }

        $purchase = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->where('product_id', $id)
            ->first();

        abort_unless($purchase && $purchase->hasAccess(), 403);

        $doc = DigitalProductDoc::query()
            ->where('id', $docId)
            ->where('product_id', $id)
            ->firstOrFail();

        abort_unless($doc->doc_type === 'pdf' && $doc->file_path, 404);

        [$disk, $path] = $this->resolveDocDiskAndPath($doc->file_path);
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
        ]);
    }

    private function activeGatewayProvider(): string
    {
        return (string) app(PaymentGatewaySettingsService::class)->getRuntimeConfig()['active_provider'];
    }

    /**
     * @return array{
     *   active_provider:string,
     *   checkout_mode:string,
     *   member_wallet_enabled:bool
     * }
     */
    private function runtimeConfig(): array
    {
        return app(PaymentGatewaySettingsService::class)->getRuntimeConfig();
    }

    private function isGatewayReady(string $provider): bool
    {
        if ($provider === 'ipaymu') {
            return app(IpaymuSettingsService::class)->isReady();
        }

        if ($provider === 'doku') {
            return app(DokuSettingsService::class)->isReady();
        }

        return app(XenditSettingsService::class)->isReady();
    }

    private function preparePurchase(User $user, DigitalProduct $product, ?ProductPurchase $existing): ProductPurchase
    {
        if ($existing instanceof ProductPurchase) {
            $existing->forceFill([
                'amount_paid' => (int) $product->price,
                'transaction_code' => $existing->transaction_code ?: 'PUR-' . strtoupper(Str::random(10)),
                'payment_status' => 'pending',
                'paid_at' => null,
            ])->save();

            return $existing;
        }

        return ProductPurchase::query()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'transaction_code' => 'PUR-' . strtoupper(Str::random(10)),
            'amount_paid' => (int) $product->price,
            'payment_status' => 'pending',
            'payment_gateway' => null,
        ]);
    }

    /**
     * @param array<string,mixed> $manualOptions
     * @return array<string,mixed>|null
     */
    private function resolveManualMethod(array $manualOptions, string $key): ?array
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
     * @return array{checkout_url:string,gateway_ref?:string}
     */
    private function createGatewayCheckout(string $provider, ProductPurchase $purchase, DigitalProduct $product, User $user): array
    {
        if ($provider === 'ipaymu') {
            $session = app(IpaymuService::class)->createRedirectPayment([
                'product' => [(string) $product->name],
                'qty' => [1],
                'price' => [(int) $product->price],
                'referenceId' => (string) $purchase->transaction_code,
                'description' => ["Pembelian {$product->name}"],
                'buyerName' => (string) $user->name,
                'buyerEmail' => (string) $user->email,
                'notifyUrl' => $this->ipaymuNotifyUrl([
                    'purpose' => 'product_purchase',
                    'purchase_id' => (int) $purchase->id,
                    'product_id' => (int) $product->id,
                    'user_id' => (int) $user->id,
                    'reference_id' => (string) $purchase->transaction_code,
                ]),
                'returnUrl' => url("/hellom/dashboard/products/{$product->slug}"),
            ]);

            return [
                'checkout_url' => (string) (data_get($session, 'Data.Url') ?: data_get($session, 'Url') ?: ''),
                'gateway_ref' => (string) (data_get($session, 'Data.SessionID') ?: data_get($session, 'Data.TransactionId') ?: ''),
            ];
        }

        if ($provider === 'doku') {
            $session = app(DokuService::class)->createCheckout([
                'order' => [
                    'amount' => (int) $product->price,
                    'invoice_number' => (string) $purchase->transaction_code,
                    'currency' => 'IDR',
                    'callback_url' => url("/hellom/dashboard/products/{$product->slug}"),
                    'callback_url_result' => url("/hellom/dashboard/products/{$product->slug}"),
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

    /**
     * @param array<string,mixed> $params
     */
    private function ipaymuNotifyUrl(array $params): string
    {
        $base = url('/api/v1/hellom/webhooks/ipaymu');
        $query = http_build_query($params);

        return $query !== '' ? $base . '?' . $query : $base;
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
     * @return array{0:\Illuminate\Contracts\Filesystem\Filesystem,1:string}
     */
    private function resolveDocDiskAndPath(string $path): array
    {
        $normalized = ltrim($path, '/');

        if (Storage::disk('local')->exists($normalized)) {
            return [Storage::disk('local'), $normalized];
        }

        if (Str::startsWith($normalized, 'storage/')) {
            $normalized = ltrim(Str::after($normalized, 'storage/'), '/');
        }

        return [Storage::disk('public'), $normalized];
    }
}
