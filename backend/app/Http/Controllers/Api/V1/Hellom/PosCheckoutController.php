<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Api\V1\Hellom\Billing\Concerns\ActivatesPlans;
use App\Models\AppCatalog;
use App\Models\CheckoutIntent;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCheckoutPayment;
use App\Models\User;
use App\Services\Billing\PromoCheckoutService;
use App\Services\Billing\CheckoutNotifier;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Hellom\PlatformMailService;
use App\Services\Hellom\SubscriptionCheckoutActivationService;
use App\Services\Payments\ChargeRequest;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\IpaymuCallbackSignature;
use App\Services\Payments\IpaymuPaymentVerifier;
use App\Services\Payments\PaymentStatus;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PosCheckoutController extends BaseApiController
{
    use ActivatesPlans;

    public function __construct(
        private readonly IpaymuSettingsService $ipaymuSettings,
        private readonly PromoCheckoutService $promos,
        private readonly SubscriptionCheckoutActivationService $activation,
        private readonly CheckoutNotifier $checkoutNotifier,
    ) {
    }

    public function options(): JsonResponse
    {
        $app = AppCatalog::query()->where('slug', 'pos')->where('is_active', true)->first();
        $plans = Plan::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->where('slug', 'like', 'pos\_%')
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get()
            ->filter(fn (Plan $plan) => $this->resolveBillingCycle($plan, null) !== null)
            ->map(fn (Plan $plan) => [
                'id' => (int) $plan->id,
                'slug' => (string) $plan->slug,
                'name' => (string) $plan->name,
                'price' => (int) $plan->price,
                'description' => (string) ($plan->description ?? ''),
                'features' => $plan->features ?? [],
                'billing_cycle' => $this->resolveBillingCycle($plan, null),
                'billing_cycles' => $plan->billing_cycles ?? [],
                'type' => $plan->type,
                'duration_days' => $plan->duration_days,
                'is_recommended' => (bool) $plan->is_recommended,
                'max_outlets' => (int) ($plan->max_outlets ?? 1),
            ])
            ->values();

        $config = $this->ipaymuSettings->getConfig();
        $channels = $this->ipaymuSettings->enabledDirectChannels();
        $ready = $config['va'] !== '' && $config['api_key'] !== '' && $channels !== [];

        return $this->ok([
            'app' => $app ? ['id' => (int) $app->id, 'slug' => 'pos', 'name' => (string) $app->name] : null,
            'plans' => $plans,
            'payment' => [
                'available' => $ready,
                'channels' => collect($channels)->map(fn (array $channel, string $key) => [
                    'key' => $key,
                    'label' => $channel[2],
                    'type' => $channel[0],
                ])->values(),
                'wallet_available' => false,
            ],
        ], 'Paket checkout POS');
    }

    public function promoPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_slug' => ['required', 'string', 'max:100'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly,lifetime'],
            'code' => ['required', 'string', 'max:50'],
        ]);
        $plan = $this->findPlan((string) $data['plan_slug']);
        if (!$plan) {
            return $this->fail('Paket tidak tersedia.', ['code' => 'PLAN_NOT_AVAILABLE'], 422);
        }

        $app = AppCatalog::query()->where('slug', 'pos')->firstOrFail();
        $preview = $this->promos->preview(
            strtoupper(trim((string) $data['code'])),
            (int) $app->id,
            (int) $plan->id,
            $this->resolvePlanAmount($plan, $this->checkoutCycle($plan, $data['billing_cycle'] ?? null))
        );
        if (!$preview) {
            return $this->fail('Kode promo tidak berlaku untuk paket ini atau sudah mencapai batas pemakaian.', [
                'code' => 'PROMO_INVALID',
            ], 422);
        }

        return $this->ok($preview, 'Kode promo berhasil diterapkan.');
    }

    public function startGuest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-]{8,20}$/'],
            'business_name' => ['required', 'string', 'max:160'],
            'plan_slug' => ['required', 'string', 'max:100'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly,lifetime'],
            'payment_channel' => ['required', 'string', 'max:30'],
            'promo_code' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:0'],
            'terms_accepted' => ['required', 'accepted'],
            'marketing' => ['nullable', 'array'],
            'purchase_event_id' => ['nullable', 'uuid'],
        ]);
        $email = strtolower(trim((string) $data['email']));
        $rateKey = 'pos-checkout:' . sha1($request->ip() . '|' . $email);
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return $this->fail('Terlalu banyak percobaan checkout. Coba lagi beberapa saat lagi.', [
                'code' => 'CHECKOUT_RATE_LIMITED',
            ], 429);
        }
        RateLimiter::hit($rateKey, 3600);

        $settings = $this->ipaymuSettings->getConfig();
        $channel = (string) $data['payment_channel'];
        if ($settings['va'] === '' || $settings['api_key'] === '' || !isset($this->ipaymuSettings->enabledDirectChannels()[$channel])) {
            return $this->fail('Pembayaran belum tersedia. Silakan coba lagi nanti atau hubungi Hellom.', [
                'code' => 'PAYMENT_UNAVAILABLE',
            ], 503);
        }

        $plan = $this->findPlan((string) $data['plan_slug']);
        $app = AppCatalog::query()->where('slug', 'pos')->where('is_active', true)->first();
        if (!$plan || !$app) {
            return $this->fail('Paket POS tidak tersedia.', ['code' => 'PLAN_NOT_AVAILABLE'], 422);
        }

        $billingCycle = $this->checkoutCycle($plan, $data['billing_cycle'] ?? null);
        $baseAmount = $this->resolvePlanAmount($plan, $billingCycle);
        $promo = null;
        if (trim((string) ($data['promo_code'] ?? '')) !== '') {
            $promo = $this->promos->preview(
                strtoupper(trim((string) $data['promo_code'])),
                (int) $app->id,
                (int) $plan->id,
                $baseAmount
            );
            if (!$promo) {
                return $this->fail('Kode promo tidak berlaku untuk paket ini atau sudah mencapai batas pemakaian.', [
                    'code' => 'PROMO_INVALID',
                ], 422);
            }
        }

        $user = User::query()->where('email', $email)->first();
        if ($user && (!$user->pending_guest_credentials || $user->isSuspended())) {
            return $this->fail('Jika kamu sudah punya akun Hellom, masuk terlebih dahulu lalu aktifkan POS dari dashboard.', [
                'code' => 'LOGIN_REQUIRED',
            ], 409);
        }

        $existingIntent = $user
            ? CheckoutIntent::query()
                ->where('user_id', $user->id)
                ->where('app_id', $app->id)
                ->whereIn('status', ['gateway_pending', 'pending'])
                ->where('expires_at', '>', now())
                ->latest('id')
                ->first()
            : null;
        if ($existingIntent instanceof CheckoutIntent) {
            return $this->fail('Checkout sebelumnya masih menunggu pembayaran. Buka kembali tautan status pembayaran dari sesi sebelumnya.', [
                'code' => 'PENDING_CHECKOUT_EXISTS',
            ], 409);
        }

        $marketing = $this->sanitizeMarketing($data['marketing'] ?? []);
        $plainStatusToken = Str::random(48);
        $result = DB::transaction(function () use ($data, $email, $user, $app, $plan, $promo, $baseAmount, $billingCycle, $marketing, $plainStatusToken): array {
            if (!$user instanceof User) {
                $user = User::query()->create([
                    'name' => trim((string) $data['name']),
                    'email' => $email,
                    'phone' => preg_replace('/[^0-9+]/', '', (string) $data['phone']),
                    'password' => Hash::make(Str::random(48)),
                    'role' => 'admin',
                    'marketing_attribution' => $marketing,
                ]);
                $user->forceFill(['pending_guest_credentials' => true])->save();
            } else {
                $user->forceFill([
                    'name' => trim((string) $data['name']),
                    'phone' => preg_replace('/[^0-9+]/', '', (string) $data['phone']),
                    'marketing_attribution' => $user->marketing_attribution ?: $marketing,
                ])->save();
            }

            $organization = Organization::query()
                ->whereHas('users', fn ($query) => $query->where('users.id', $user->id))
                ->where('status', 'pending')
                ->first();
            if (!$organization) {
                if ($user->wasRecentlyCreated === false && !$user->pending_guest_credentials) {
                    throw new \DomainException('Akun ini tidak dapat digunakan untuk checkout tamu.');
                }
                $organization = Organization::query()->create([
                    'name' => trim((string) $data['business_name']),
                    'slug' => Str::slug((string) $data['business_name']) ?: 'usaha',
                    'status' => 'pending',
                ]);
                $organization->users()->attach($user->id, ['role' => 'owner']);
                $user->forceFill(['current_organization_id' => $organization->id])->save();
            } else {
                $organization->forceFill(['name' => trim((string) $data['business_name'])])->save();
            }

            $discount = (int) ($promo['discount_amount'] ?? 0);
            $amount = max(0, $baseAmount - $discount);
            $subscription = Subscription::query()->create([
                'organization_id' => (int) $organization->id,
                'app_id' => (int) $app->id,
                'plan_id' => (int) $plan->id,
                'status' => 'pending_payment',
                'amount' => $amount,
                'currency' => 'IDR',
                'billing_cycle' => $billingCycle,
                'metadata' => [
                    'created_by_user_id' => (int) $user->id,
                    'payment_flow' => 'direct',
                    'guest_checkout' => true,
                ],
            ]);
            $intent = CheckoutIntent::query()->create([
                'organization_id' => (int) $organization->id,
                'user_id' => (int) $user->id,
                'app_id' => (int) $app->id,
                'plan_id' => (int) $plan->id,
                'subscription_id' => (int) $subscription->id,
                'intent_token' => Str::upper('chk_' . Str::random(24)),
                'status' => 'gateway_pending',
                'amount' => $amount,
                'currency' => 'IDR',
                'expires_at' => now()->addHour(),
                'status_token_hash' => hash('sha256', $plainStatusToken),
                'metadata' => [
                    'checkout_mode' => 'gateway_automatic',
                    'payment_flow' => 'direct',
                    'guest_checkout' => true,
                    'app_slug' => 'pos',
                    'plan_slug' => (string) $plan->slug,
                    'billing_cycle' => $billingCycle,
                    'base_amount' => $baseAmount,
                    'discount_amount' => $discount,
                    'promo' => $promo,
                    'marketing' => $marketing,
                    'purchase_event_id' => $data['purchase_event_id'] ?? (string) Str::uuid(),
                ],
            ]);
            $invoice = Invoice::query()->create([
                'organization_id' => (int) $organization->id,
                'subscription_id' => (int) $subscription->id,
                'invoice_number' => 'INV-' . strtoupper(now()->format('Ymd')) . '-' . Str::upper(Str::random(6)),
                'status' => 'issued',
                'amount' => $baseAmount,
                'tax' => 0,
                'total' => $amount,
                'currency' => 'IDR',
                'line_items' => [[
                    'description' => $app->name . ' - ' . $plan->name . ' (' . match ($billingCycle) { 'yearly' => 'Tahunan', 'monthly' => 'Bulanan', default => 'Sekali bayar' } . ')',
                    'amount' => $baseAmount,
                    'discount' => $discount,
                ]],
                'issued_at' => now(),
                'due_at' => $intent->expires_at,
                'metadata' => [
                    'provider' => 'ipaymu',
                    'intent_token' => $intent->intent_token,
                    'billing_cycle' => $billingCycle,
                    'promo' => $promo,
                    'marketing_attribution' => $marketing,
                ],
            ]);
            $intent->forceFill(['metadata' => [...$intent->metadata, 'invoice_id' => (int) $invoice->id]])->save();
            Log::info('POS guest checkout created', [
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'checkout_intent_id' => $intent->id,
                'invoice_id' => $invoice->id,
            ]);

            return compact('user', 'organization', 'subscription', 'intent', 'invoice');
        });

        return $this->createPaymentResponse(
            $result['intent'],
            $result['invoice'],
            (string) $data['payment_channel'],
            $plainStatusToken,
            $result['user'],
            true,
            $request
        );
    }

    public function status(string $token, Request $request): JsonResponse
    {
        $intent = $this->findByStatusToken($token);
        if (!$intent) {
            return $this->fail('Transaksi tidak ditemukan atau tautan sudah tidak berlaku.', [
                'code' => 'CHECKOUT_NOT_FOUND',
            ], 404);
        }

        $attempt = $intent->payments()->latest('id')->first();
        if ($attempt && $intent->status !== 'confirmed') {
            $this->reconcileAttempt($intent, $attempt, 'status_check');
            $intent->refresh();
            $attempt->refresh();
        }
        if ($intent->status !== 'confirmed' && $intent->expires_at?->isPast()) {
            $this->expireCheckout($intent);
            $intent->refresh();
            $attempt?->refresh();
        }

        $finalStatus = match ((string) $intent->status) {
            'confirmed', 'paid' => 'paid',
            'expired' => 'expired',
            'failed', 'rejected', 'cancelled', 'refunded' => 'failed',
            default => 'pending',
        };
        $intent->loadMissing(['plan', 'app', 'subscription', 'payments']);

        return $this->ok([
            'status' => $finalStatus,
            'invoice_number' => (string) data_get($intent->metadata, 'invoice_number', ''),
            'amount' => (int) $intent->amount,
            'base_amount' => (int) data_get($intent->metadata, 'base_amount', $intent->amount),
            'discount_amount' => (int) data_get($intent->metadata, 'discount_amount', 0),
            'promo_code' => (string) data_get($intent->metadata, 'promo.code', ''),
            'plan' => [
                'name' => (string) ($intent->plan?->name ?? 'POS'),
                'slug' => (string) ($intent->plan?->slug ?? ''),
            ],
            'payment' => $this->paymentSummary($attempt),
            'expires_at' => optional($attempt?->expires_at ?? $intent->expires_at)?->toIso8601String(),
            'paid_at' => optional($intent->updated_at)?->toIso8601String(),
            'login_url' => $finalStatus === 'paid' ? FrontendUrl::to('/login') : null,
            'help_url' => FrontendUrl::to('/bantuan'),
            'purchase_event_id' => (string) data_get($intent->metadata, 'purchase_event_id', ''),
            'email' => $this->maskEmail((string) $intent->user?->email),
        ], 'Status pembayaran');
    }

    public function replacePayment(string $token, Request $request): JsonResponse
    {
        $data = $request->validate(['payment_channel' => ['required', 'string', 'max:30']]);
        $intent = $this->findByStatusToken($token);
        if (!$intent) {
            return $this->fail('Transaksi tidak ditemukan.', ['code' => 'CHECKOUT_NOT_FOUND'], 404);
        }
        if (in_array((string) $intent->status, ['confirmed', 'paid'], true)) {
            return $this->fail('Pembayaran sudah berhasil. Tidak perlu membuat pembayaran baru.', [
                'code' => 'CHECKOUT_ALREADY_PAID',
            ], 422);
        }

        $invoiceId = (int) data_get($intent->metadata, 'invoice_id', 0);
        $invoice = Invoice::query()->find($invoiceId);
        if (!$invoice) {
            return $this->fail('Invoice tidak ditemukan.', ['code' => 'INVOICE_NOT_FOUND'], 404);
        }
        if ($invoice->status === 'paid') {
            return $this->fail('Invoice sudah dibayar.', ['code' => 'INVOICE_ALREADY_PAID'], 422);
        }

        $intent->forceFill([
            'status' => 'gateway_pending',
            'expires_at' => now()->addHour(),
        ])->save();
        $intent->payments()->where('status', 'pending')->update(['status' => 'replaced']);
        $invoice->forceFill(['status' => 'issued', 'due_at' => $intent->expires_at])->save();
        $tokenValue = $token;

        return $this->createPaymentResponse(
            $intent->fresh(['user', 'plan', 'app']),
            $invoice,
            (string) $data['payment_channel'],
            $tokenValue,
            $intent->user,
            (bool) data_get($intent->metadata, 'guest_checkout', false),
            $request
        );
    }

    public function notify(Request $request, IpaymuCallbackSignature $signature): JsonResponse
    {
        $config = $this->ipaymuSettings->getConfig();
        if (!$signature->valid($request, (string) $config['va'])) {
            Log::warning('POS checkout iPaymu callback signature rejected', [
                'external_id' => $request->header('X-External-ID'),
            ]);
            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $transactionId = trim((string) ($request->input('trx_id') ?? $request->input('transaction_id') ?? $request->input('transactionId') ?? ''));
        $reference = trim((string) ($request->input('reference_id') ?? $request->input('referenceId') ?? ''));
        if ($transactionId === '' || $reference === '') {
            return response()->json(['status' => 'invalid_payload'], 422);
        }

        $attempt = SubscriptionCheckoutPayment::query()
            ->with(['intent.subscription.plan', 'intent.app', 'intent.plan', 'intent.user'])
            ->where('transaction_id', $transactionId)
            ->first();
        if (!$attempt) {
            $invoice = Invoice::query()->where('invoice_number', $reference)->first();
            if ($invoice) {
                $attempt = SubscriptionCheckoutPayment::query()
                    ->with(['intent.subscription.plan', 'intent.app', 'intent.plan', 'intent.user'])
                    ->where('invoice_id', $invoice->id)
                    ->whereIn('status', ['pending', 'replaced'])
                    ->latest('id')
                    ->first();
            }
        }
        if (!$attempt || !hash_equals((string) $attempt->reference_id, $reference)) {
            return response()->json(['status' => 'unknown_transaction'], 404);
        }

        $outcome = $this->reconcileAttempt($attempt->intent, $attempt, 'ipaymu_callback');
        Log::info('POS checkout iPaymu callback handled', [
            'external_id' => $request->header('X-External-ID'),
            'checkout_intent_id' => $attempt->checkout_intent_id,
            'payment_id' => $attempt->id,
            'outcome' => $outcome,
        ]);

        return response()->json(['status' => $outcome === 'error' ? 'retry' : 'ok'], $outcome === 'error' ? 503 : 200);
    }

    private function createPaymentResponse(
        CheckoutIntent $intent,
        Invoice $invoice,
        string $channel,
        string $statusToken,
        User $user,
        bool $guest,
        Request $request,
    ): JsonResponse {
        $channels = $this->ipaymuSettings->enabledDirectChannels();
        if (!isset($channels[$channel])) {
            return $this->fail('Metode pembayaran tidak tersedia. Silakan pilih metode lain.', [
                'code' => 'PAYMENT_CHANNEL_UNAVAILABLE',
            ], 422);
        }
        $config = $this->ipaymuSettings->getConfig();
        if ($config['va'] === '' || $config['api_key'] === '') {
            return $this->fail('Pembayaran belum tersedia. Silakan coba lagi nanti atau hubungi Hellom.', [
                'code' => 'PAYMENT_UNAVAILABLE',
            ], 503);
        }

        try {
            $callbackUrl = (string) config('payments.providers.ipaymu.notify_url');
            if ($callbackUrl === '') {
                $callbackUrl = url('/api/v1/hellom/public/pos-checkout/ipaymu/callback');
            }
            $charge = app(GatewayRegistry::class)->get('ipaymu')->createCharge(new ChargeRequest(
                reference: (string) $invoice->invoice_number,
                amount: (int) $intent->amount,
                productName: 'Hellom POS - ' . (string) $intent->plan?->name,
                buyerName: (string) $user->name,
                buyerEmail: (string) $user->email,
                buyerPhone: (string) ($user->phone ?? ''),
                returnUrl: FrontendUrl::to('/pembayaran/' . rawurlencode($statusToken)),
                preferredMethod: $channel,
                notifyUrlOverride: $callbackUrl,
            ));

            $expiresAt = $charge->expiresAt
                ? Carbon::parse($charge->expiresAt)
                : now()->addHour();
            $attempt = SubscriptionCheckoutPayment::query()->create([
                'checkout_intent_id' => (int) $intent->id,
                'invoice_id' => (int) $invoice->id,
                'provider' => 'ipaymu',
                'reference_id' => (string) $invoice->invoice_number,
                'transaction_id' => $charge->transactionId,
                'session_id' => $charge->gatewayRef,
                'method' => $charge->mode,
                'channel' => $channel,
                'channel_label' => $charge->channelLabel,
                'status' => 'pending',
                'amount' => (int) $intent->amount,
                'payment_number' => $charge->vaNumber,
                'qr_string' => $charge->qrString,
                'qr_image_url' => $charge->qrImageUrl,
                'expires_at' => $expiresAt,
                'gateway_metadata' => ['provider' => 'ipaymu'],
            ]);
            $intentMeta = is_array($intent->metadata) ? $intent->metadata : [];
            $intentMeta['invoice_id'] = (int) $invoice->id;
            $intentMeta['invoice_number'] = (string) $invoice->invoice_number;
            $intentMeta['latest_payment_id'] = (int) $attempt->id;
            $intentMeta['ipaymu'] = [
                'transaction_id' => $charge->transactionId,
                'reference_id' => (string) $invoice->invoice_number,
                'payment_session_id' => $charge->gatewayRef,
                'payment_instructions' => [
                    'mode' => $charge->mode,
                    'channel' => $channel,
                    'channel_label' => $charge->channelLabel,
                    'va_number' => $charge->vaNumber,
                    'qr_string' => $charge->qrString,
                    'qr_image_url' => $charge->qrImageUrl,
                ],
            ];
            $intent->forceFill([
                'metadata' => $intentMeta,
                'expires_at' => $expiresAt,
            ])->save();
            $invoice->forceFill([
                'status' => 'issued',
                'due_at' => $expiresAt,
                'metadata' => [...(is_array($invoice->metadata) ? $invoice->metadata : []), 'ipaymu' => [
                    'transaction_id' => $charge->transactionId,
                    'reference_id' => (string) $invoice->invoice_number,
                    'payment_id' => (int) $attempt->id,
                ]],
            ])->save();
            $statusUrl = FrontendUrl::to('/pembayaran/' . rawurlencode($statusToken));
            $freshIntent = $intent->fresh(['subscription.organization.users', 'app', 'plan', 'user']);
            if ($freshIntent instanceof CheckoutIntent) {
                $this->checkoutNotifier->sendCheckoutStartedNotifications($freshIntent, $invoice, null, $statusUrl);
            }

            return $this->ok([
                'status_token' => $statusToken,
                'status_url' => FrontendUrl::to('/pembayaran/' . rawurlencode($statusToken)),
                'payment' => $this->paymentSummary($attempt),
                'expires_at' => $expiresAt->toIso8601String(),
                'amount' => (int) $intent->amount,
            ], 'Instruksi pembayaran berhasil dibuat.', 201);
        } catch (\Throwable $exception) {
            Log::error('POS iPaymu direct charge failed', [
                'checkout_intent_id' => $intent->id,
                'invoice_id' => $invoice->id,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);
            return $this->fail('Pembayaran belum berhasil dibuat. Coba lagi atau pilih metode pembayaran lain.', [
                'code' => 'IPAYMU_CHECKOUT_FAILED',
            ], 503);
        }
    }

    private function reconcileAttempt(CheckoutIntent $intent, SubscriptionCheckoutPayment $attempt, string $source): string
    {
        if ($attempt->status === 'paid') {
            return 'duplicate';
        }
        if (!$attempt->transaction_id) {
            return 'pending';
        }

        try {
            $check = app(IpaymuPaymentVerifier::class)->verify(
                (string) $attempt->reference_id,
                (int) $attempt->amount,
                (string) $attempt->transaction_id,
                true
            );
        } catch (\Throwable $exception) {
            Log::warning('POS iPaymu payment status check failed', [
                'payment_id' => $attempt->id,
                'exception' => get_class($exception),
            ]);
            return 'error';
        }

        if (!$check['ok']) {
            $state = (string) $check['status']->state;
            if (in_array($state, [PaymentStatus::FAILED, PaymentStatus::EXPIRED, PaymentStatus::REFUNDED], true)) {
                DB::transaction(function () use ($intent, $attempt, $state): void {
                    $lockedIntent = CheckoutIntent::query()->lockForUpdate()->find((int) $intent->id);
                    $lockedAttempt = SubscriptionCheckoutPayment::query()->lockForUpdate()->find((int) $attempt->id);
                    if (!$lockedIntent || !$lockedAttempt || $lockedAttempt->status === 'paid') {
                        return;
                    }
                    $lockedAttempt->forceFill(['status' => $state])->save();
                    if (!in_array((string) $lockedIntent->status, ['confirmed', 'paid'], true)) {
                        $lockedIntent->forceFill(['status' => $state])->save();
                        if ($state === PaymentStatus::EXPIRED) {
                            $lockedIntent->subscription?->forceFill(['status' => 'expired'])->save();
                            Invoice::query()
                                ->whereKey((int) data_get($lockedIntent->metadata, 'invoice_id'))
                                ->where('status', 'issued')
                                ->update(['status' => 'void']);
                        }
                    }
                }, 3);
                Log::info('POS iPaymu payment status updated', [
                    'checkout_intent_id' => $intent->id,
                    'payment_id' => $attempt->id,
                    'status' => $state,
                    'source' => $source,
                ]);
            }
            return $state === PaymentStatus::UNKNOWN ? 'pending' : $state;
        }

        $activated = DB::transaction(function () use ($intent, $attempt, $check, $source): bool {
            $lockedIntent = CheckoutIntent::query()->lockForUpdate()->find((int) $intent->id);
            $lockedAttempt = SubscriptionCheckoutPayment::query()->lockForUpdate()->find((int) $attempt->id);
            if (!$lockedIntent || !$lockedAttempt) {
                return false;
            }
            if ($lockedAttempt->status === 'paid') {
                return false;
            }
            if (in_array((string) $lockedIntent->status, ['confirmed', 'paid'], true)) {
                $lockedAttempt->forceFill(['status' => 'duplicate_paid', 'paid_at' => now()])->save();
                Log::warning('Duplicate paid POS invoice detected', [
                    'checkout_intent_id' => $lockedIntent->id,
                    'payment_id' => $lockedAttempt->id,
                    'source' => $source,
                ]);
                return false;
            }

            $lockedAttempt->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
                'gateway_metadata' => [
                    ...(is_array($lockedAttempt->gateway_metadata) ? $lockedAttempt->gateway_metadata : []),
                    'verified_status' => $check['status']->state,
                    'verification_source' => $source,
                ],
            ])->save();
            $meta = is_array($lockedIntent->metadata) ? $lockedIntent->metadata : [];
            $meta['invoice_id'] = (int) $lockedAttempt->invoice_id;
            $meta['verified_late_payment'] = true;
            return $this->activation->confirmGatewayCheckout($lockedIntent, [
                'transaction_id' => (string) ($check['status']->transactionId ?: $lockedAttempt->transaction_id),
                'invoice_id' => (int) $lockedAttempt->invoice_id,
                'payment_id' => (int) $lockedAttempt->id,
                'verified_gateway_payment' => true,
            ], 'iPaymu');
        }, 3);

        $attempt->refresh();
        $intent->refresh();
        if ($intent->status === 'confirmed' && $attempt->status === 'paid') {
            if ($activated || $intent->user?->pending_guest_credentials) {
                $this->sendGuestSetupEmail($intent);
            }
            if ($activated) {
                $this->recordPurchaseEvent($intent);
            }
            return 'paid';
        }
        return $attempt->status === 'duplicate_paid' ? 'duplicate_paid' : 'pending';
    }

    private function sendGuestSetupEmail(CheckoutIntent $intent): void
    {
        $intent->loadMissing(['user', 'plan']);
        $user = $intent->user;
        if (!$user instanceof User || !$user->pending_guest_credentials) {
            return;
        }

        $claimed = CheckoutIntent::query()
            ->whereKey($intent->id)
            ->whereNull('guest_setup_email_sent_at')
            ->update(['guest_setup_email_sent_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        try {
            $setupUrl = FrontendUrl::to('/reset-password?' . http_build_query([
                'token' => Password::broker('account_setup')->createToken($user),
                'email' => (string) $user->email,
                'setup' => 1,
            ]));
            $delivery = app(PlatformMailService::class)->sendTo((string) $user->email, new \App\Mail\HellomCheckoutStatusMail(
                'Pembayaran berhasil — atur kata sandi akun Hellom',
                [
                    'headline' => 'Akun Hellom dan POS kamu siap digunakan',
                    'intro' => 'Pembayaran sudah terverifikasi. Atur kata sandi agar kamu bisa masuk dan mulai menggunakan POS.',
                    'details' => [
                        'Paket' => (string) ($intent->plan?->name ?? 'POS'),
                        'Nomor invoice' => (string) data_get($intent->metadata, 'invoice_number', ''),
                        'Email akun' => (string) $user->email,
                    ],
                    'cta_url' => $setupUrl,
                    'cta_label' => 'Atur kata sandi',
                    'status_url' => FrontendUrl::to('/login'),
                    'status_label' => 'Masuk ke Hellom',
                    'closing' => 'Jika tautan sudah kedaluwarsa, gunakan fitur Lupa kata sandi di halaman masuk.',
                ],
            ));
            if ($delivery['sent'] ?? false) {
                $user->forceFill(['pending_guest_credentials' => false])->save();
                return;
            }
            throw new \RuntimeException((string) ($delivery['error'] ?? 'Email tidak terkirim.'));
        } catch (\Throwable $exception) {
            CheckoutIntent::query()->whereKey($intent->id)->update(['guest_setup_email_sent_at' => null]);
            Log::warning('POS guest account setup email failed', [
                'checkout_intent_id' => $intent->id,
                'user_id' => $user->id,
                'exception' => get_class($exception),
            ]);
        }
    }

    private function recordPurchaseEvent(CheckoutIntent $intent): void
    {
        $marketing = data_get($intent->metadata, 'marketing');
        $eventId = (string) data_get($intent->metadata, 'purchase_event_id', '');
        if (!is_array($marketing) || $eventId === '') {
            return;
        }
        app(\App\Services\PlatformMarketing::class)->record(
            'Purchase',
            $eventId,
            $marketing,
            (int) $intent->amount,
            'pos',
            $intent->user,
        );
    }

    private function findByStatusToken(string $token): ?CheckoutIntent
    {
        if (strlen($token) < 32 || strlen($token) > 128) {
            return null;
        }
        return CheckoutIntent::query()
            ->with(['user', 'plan', 'app', 'subscription'])
            ->where('status_token_hash', hash('sha256', $token))
            ->first();
    }

    private function expireCheckout(CheckoutIntent $intent): void
    {
        DB::transaction(function () use ($intent): void {
            $locked = CheckoutIntent::query()->lockForUpdate()->find($intent->id);
            if (!$locked || in_array((string) $locked->status, ['confirmed', 'paid'], true)) {
                return;
            }
            $locked->forceFill(['status' => 'expired'])->save();
            $locked->payments()->where('status', 'pending')->update(['status' => 'expired']);
            if ($locked->subscription) {
                $locked->subscription->forceFill(['status' => 'expired'])->save();
            }
            Invoice::query()->whereKey((int) data_get($locked->metadata, 'invoice_id'))->where('status', 'issued')->update(['status' => 'void']);
        }, 3);
    }

    private function paymentSummary(?SubscriptionCheckoutPayment $payment): ?array
    {
        if (!$payment) {
            return null;
        }
        return [
            'id' => (int) $payment->id,
            'method' => (string) $payment->method,
            'channel' => (string) $payment->channel,
            'channel_label' => (string) ($payment->channel_label ?? ''),
            'payment_number' => $payment->payment_number,
            'qr_string' => $payment->qr_string,
            'qr_image_url' => $payment->qr_image_url,
            'status' => (string) $payment->status,
            'expires_at' => optional($payment->expires_at)?->toIso8601String(),
        ];
    }

    private function findPlan(string $slug): ?Plan
    {
        return Plan::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->where('is_visible', true)
            ->where('slug', 'like', 'pos\_%')
            ->first();
    }

    private function checkoutCycle(Plan $plan, ?string $requested): string
    {
        $cycle = $this->resolveBillingCycle($plan, $requested);
        if ($cycle === null || ($requested !== null && $requested !== $cycle)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'billing_cycle' => 'Periode pembayaran tidak tersedia untuk paket ini.',
            ]);
        }
        return $cycle;
    }

    private function sanitizeMarketing(array $input): array
    {
        $result = [];
        foreach (['consent', 'visitor_id', 'session_id', 'source', 'medium', 'campaign', 'content', 'fbclid', 'fbp', 'fbc', 'path'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $result[$key] = mb_substr($input[$key], 0, in_array($key, ['fbp', 'fbc', 'fbclid'], true) ? 255 : 120);
            }
        }
        if (($result['consent'] ?? '') !== 'granted') {
            return [];
        }
        $result['ip'] = request()->ip();
        $result['ua'] = mb_substr((string) request()->userAgent(), 0, 500);
        return $result;
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($name === '' || $domain === '') {
            return '';
        }
        return mb_substr($name, 0, 1) . str_repeat('*', max(1, mb_strlen($name) - 1)) . '@' . $domain;
    }
}
