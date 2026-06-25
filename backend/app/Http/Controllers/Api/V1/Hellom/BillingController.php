<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\AppCatalog;
use App\Models\CheckoutIntent;
use App\Models\Entitlement;
use App\Models\Invoice;
use App\Models\LandingBlock;
use App\Models\LandingPageOrder;
use App\Models\OrganizationLandingPage;
use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Hellom\LandingSaleService;
use App\Services\NotificationService;
use App\Http\Controllers\Api\V1\Hellom\InvoiceController;
use App\Mail\HellomBillingNotificationMail;
use App\Mail\HellomCheckoutStatusMail;
use App\Services\Hellom\DokuService;
use App\Services\Hellom\DokuSettingsService;
use App\Services\Hellom\IpaymuService;
use App\Services\Hellom\SubscriptionCheckoutActivationService;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Hellom\ManualPaymentSettingsService;
use App\Services\Hellom\PaymentGatewaySettingsService;
use App\Services\Hellom\PosProvisioningService;
use App\Services\Hellom\PlatformMailService;
use App\Services\Hellom\XenditService;
use App\Services\Hellom\XenditSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BillingController extends BaseApiController
{
    public function __construct(
        private readonly PlatformMailService $platformMailService,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function gatewayStatus(): JsonResponse
    {
        $runtime = $this->gatewayRuntime()->getRuntimeConfig();
        $provider = $this->activeGatewayProvider();
        $activeConfig = $this->activeGatewayConfig();
        $balance = null;
        $manualConfig = $this->manualPaymentSettings()->publicOptions();

        if ($provider === 'xendit' && $activeConfig['is_ready']) {
            try {
                $balance = [
                    'currency' => 'IDR',
                    'amount' => (int) data_get($this->xendit()->getBalance('IDR'), 'balance', 0),
                ];
            } catch (\Throwable) {
                $balance = null;
            }
        }

        return $this->ok([
            'provider' => $provider,
            'active_provider' => $provider,
            'mode' => (string) ($activeConfig['mode'] ?? 'sandbox'),
            'is_ready' => (bool) ($activeConfig['is_ready'] ?? false),
            'checkout_mode' => (string) $runtime['checkout_mode'],
            'member_wallet_enabled' => (bool) $runtime['member_wallet_enabled'],
            'supports' => [
                'wallet_topup' => (bool) $runtime['member_wallet_enabled'] && (bool) ($activeConfig['is_ready'] ?? false),
                'subscription_wallet' => (bool) $runtime['member_wallet_enabled'],
                'subscription_direct_invoice' => (bool) ($activeConfig['is_ready'] ?? false),
                'virtual_account' => in_array($provider, ['xendit', 'doku'], true) ? (bool) ($activeConfig['is_ready'] ?? false) : false,
                'qris' => (bool) ($activeConfig['is_ready'] ?? false),
                'disbursement' => $provider === 'xendit' && (bool) ($activeConfig['is_ready'] ?? false),
                'webhook' => $this->providerCallbackTokenConfigured($provider),
                'manual_payment' => (bool) $manualConfig['enabled'] && count($manualConfig['methods']) > 0,
            ],
            'webhook' => [
                'path' => $this->providerWebhookPath($provider),
                'callback_token_configured' => $this->providerCallbackTokenConfigured($provider),
            ],
            'manual_confirmation' => [
                'enabled' => $runtime['checkout_mode'] === 'manual_confirmation',
                'label' => $runtime['checkout_mode'] === 'manual_confirmation'
                    ? 'Pembayaran langsung masuk antrean konfirmasi owner'
                    : 'Manual confirmation dimatikan',
            ],
            'providers' => [
                'xendit' => $this->xenditSettings()->publicConfigSummary(),
                'ipaymu' => $this->ipaymuSettings()->publicConfigSummary(),
                'doku' => $this->dokuSettings()->publicConfigSummary(),
            ],
            'manual_payment' => $manualConfig,
            'balance' => $balance,
        ], 'Payment gateway status');
    }

    public function adminGatewayConfig(): JsonResponse
    {
        $runtime = $this->gatewayRuntime()->getRuntimeConfig();
        $xendit = $this->xenditSettings()->publicConfigSummary();
        $ipaymu = $this->ipaymuSettings()->publicConfigSummary();
        $doku = $this->dokuSettings()->publicConfigSummary();
        $xenditBalance = null;

        if ($xendit['is_ready']) {
            try {
                $xenditBalance = [
                    'currency' => 'IDR',
                    'amount' => (int) data_get($this->xendit()->getBalance('IDR'), 'balance', 0),
                ];
            } catch (\Throwable $exception) {
                $xenditBalance = [
                    'currency' => 'IDR',
                    'amount' => null,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $this->ok([
            'active_provider' => (string) $runtime['active_provider'],
            'checkout_mode' => (string) $runtime['checkout_mode'],
            'member_wallet_enabled' => (bool) $runtime['member_wallet_enabled'],
            'sale_commission_percent' => (float) $runtime['sale_commission_percent'],
            'providers' => [
                'xendit' => [
                    ...$xendit,
                    'webhook' => [
                        'path' => $this->providerWebhookPath('xendit'),
                        'callback_token_configured' => $xendit['callback_token_masked'] !== null,
                    ],
                    'balance' => $xenditBalance,
                ],
                'ipaymu' => [
                    ...$ipaymu,
                    'webhook' => [
                        'path' => $this->providerWebhookPath('ipaymu'),
                        'callback_token_configured' => $ipaymu['callback_token_masked'] !== null,
                    ],
                    'balance' => null,
                ],
                'doku' => [
                    ...$doku,
                    'webhook' => [
                        'path' => $this->providerWebhookPath('doku'),
                        'callback_token_configured' => $doku['callback_token_masked'] !== null,
                    ],
                    'balance' => null,
                ],
            ],
            'manual_payment' => $this->manualPaymentSettings()->getConfig(),
        ], 'Admin gateway config');
    }

    public function updateAdminGatewayConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'in:xendit,ipaymu,doku'],
            'secret_key' => ['nullable', 'string', 'min:10', 'max:255'],
            'client_id' => ['nullable', 'string', 'min:3', 'max:255'],
            'va' => ['nullable', 'string', 'min:3', 'max:100'],
            'api_key' => ['nullable', 'string', 'min:10', 'max:255'],
            'callback_token' => ['nullable', 'string', 'min:8', 'max:255'],
            'is_production' => ['required', 'boolean'],
            'va_channels' => ['nullable'],
            'payment_method_types' => ['nullable'],
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => ['string', 'max:30'],
        ]);

        $provider = (string) $validated['provider'];
        $previous = $provider === 'ipaymu'
            ? $this->ipaymuSettings()->getConfig()
            : ($provider === 'doku' ? $this->dokuSettings()->getConfig() : $this->xenditSettings()->getConfig());

        if ($provider === 'ipaymu') {
            $config = $this->ipaymuSettings()->saveConfig([
                'va' => $validated['va'] ?? null,
                'api_key' => $validated['api_key'] ?? null,
                'callback_token' => $validated['callback_token'] ?? null,
                'is_production' => $validated['is_production'],
                'payment_methods' => $validated['payment_methods'] ?? null,
            ]);
        } elseif ($provider === 'doku') {
            $config = $this->dokuSettings()->saveConfig([
                'client_id' => $validated['client_id'] ?? null,
                'secret_key' => $validated['secret_key'] ?? null,
                'callback_token' => $validated['callback_token'] ?? null,
                'is_production' => $validated['is_production'],
                'payment_method_types' => $validated['payment_method_types'] ?? null,
            ]);
        } else {
            $config = $this->xenditSettings()->saveConfig($validated);
        }

        $generatedCallbackToken = ($previous['callback_token'] ?? '') === ''
            && trim((string) ($validated['callback_token'] ?? '')) === ''
            && ($config['callback_token'] ?? '') !== ''
                ? $config['callback_token']
                : null;

        return $this->ok([
            'provider' => $provider,
            'generated_callback_token' => $generatedCallbackToken,
            'config' => $provider === 'ipaymu'
                ? [
                    ...$this->ipaymuSettings()->publicConfigSummary(),
                    'webhook' => [
                        'path' => $this->providerWebhookPath('ipaymu'),
                        'callback_token_configured' => ($config['callback_token'] ?? '') !== '',
                    ],
                ]
                : ($provider === 'doku'
                    ? [
                        ...$this->dokuSettings()->publicConfigSummary(),
                        'webhook' => [
                            'path' => $this->providerWebhookPath('doku'),
                            'callback_token_configured' => ($config['callback_token'] ?? '') !== '',
                        ],
                    ]
                : [
                    ...$this->xenditSettings()->publicConfigSummary(),
                    'webhook' => [
                        'path' => $this->providerWebhookPath('xendit'),
                        'callback_token_configured' => ($config['callback_token'] ?? '') !== '',
                    ],
                ]),
        ], 'Admin gateway config updated');
    }

    public function resetIpaymuConfig(): JsonResponse
    {
        $config = $this->ipaymuSettings()->resetConfig();

        return $this->ok([
            'provider' => 'ipaymu',
            'config' => [
                ...$this->ipaymuSettings()->publicConfigSummary(),
                'webhook' => [
                    'path' => $this->providerWebhookPath('ipaymu'),
                    'callback_token_configured' => ($config['callback_token'] ?? '') !== '',
                ],
            ],
        ], 'iPaymu config reset');
    }

    public function adminManualPaymentConfig(): JsonResponse
    {
        return $this->ok($this->manualPaymentSettings()->getConfig(), 'Manual payment config');
    }

    public function updateAdminManualPaymentConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'methods.bank_transfer.enabled' => ['nullable', 'boolean'],
            'methods.bank_transfer.label' => ['nullable', 'string', 'max:100'],
            'methods.bank_transfer.bank_name' => ['nullable', 'string', 'max:100'],
            'methods.bank_transfer.account_name' => ['nullable', 'string', 'max:100'],
            'methods.bank_transfer.account_number' => ['nullable', 'string', 'max:100'],
            'methods.bank_transfer.instructions' => ['nullable', 'string', 'max:1000'],
            'methods.gopay.enabled' => ['nullable', 'boolean'],
            'methods.gopay.label' => ['nullable', 'string', 'max:100'],
            'methods.gopay.account_name' => ['nullable', 'string', 'max:100'],
            'methods.gopay.account_number' => ['nullable', 'string', 'max:100'],
            'methods.gopay.instructions' => ['nullable', 'string', 'max:1000'],
            'methods.dana.enabled' => ['nullable', 'boolean'],
            'methods.dana.label' => ['nullable', 'string', 'max:100'],
            'methods.dana.account_name' => ['nullable', 'string', 'max:100'],
            'methods.dana.account_number' => ['nullable', 'string', 'max:100'],
            'methods.dana.instructions' => ['nullable', 'string', 'max:1000'],
            'methods.qris.enabled' => ['nullable', 'boolean'],
            'methods.qris.label' => ['nullable', 'string', 'max:100'],
            'methods.qris.instructions' => ['nullable', 'string', 'max:1000'],
            'images.bank_transfer' => ['nullable', 'image', 'max:4096'],
            'images.gopay' => ['nullable', 'image', 'max:4096'],
            'images.dana' => ['nullable', 'image', 'max:4096'],
            'images.qris' => ['nullable', 'image', 'max:4096'],
        ]);

        $payload = $validated;
        unset($payload['images']);

        foreach (['bank_transfer', 'gopay', 'dana', 'qris'] as $methodKey) {
            if ($request->hasFile("images.{$methodKey}")) {
                $path = $request->file("images.{$methodKey}")->store('hellom/manual-payments', 'public');
                data_set($payload, "methods.{$methodKey}.image_path", $path);
            }
        }

        return $this->ok(
            $this->manualPaymentSettings()->saveConfig($payload),
            'Manual payment config updated'
        );
    }

    public function checkoutRuntimeConfig(): JsonResponse
    {
        return $this->ok(
            $this->gatewayRuntime()->getRuntimeConfig(),
            'Checkout runtime config'
        );
    }

    public function updateCheckoutRuntimeConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'active_provider' => ['required', 'in:xendit,ipaymu,doku'],
            'checkout_mode' => ['required', 'in:manual_confirmation,gateway_automatic,xendit_automatic'],
            'member_wallet_enabled' => ['required', 'boolean'],
            'sale_commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return $this->ok(
            $this->gatewayRuntime()->saveRuntimeConfig($validated),
            'Checkout runtime config updated'
        );
    }

    public function checkoutStart(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'plan_slug' => ['required', 'string'],
            'payment_flow' => ['required', 'in:wallet,direct'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly,lifetime'],
            'manual_payment_method' => ['nullable', 'in:bank_transfer,gopay,dana,qris'],
        ]);

        $app = AppCatalog::query()
            ->where('slug', (string) $validated['app_slug'])
            ->where('is_active', true)
            ->first();

        if (!$app) {
            return $this->fail('App not found', ['code' => 'APP_NOT_FOUND'], 404);
        }

        $plan = Plan::query()
            ->where('slug', (string) $validated['plan_slug'])
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            return $this->fail('Plan not found', ['code' => 'PLAN_NOT_FOUND'], 404);
        }

        if (!$this->planEligibleForApp((string) $plan->slug, (string) $app->slug)) {
            return $this->fail('Plan is not eligible for selected app', ['code' => 'PLAN_NOT_ELIGIBLE'], 422);
        }

        $billingCycle = $this->resolveBillingCycle($plan, isset($validated['billing_cycle']) ? (string) $validated['billing_cycle'] : null);
        if ($billingCycle === null) {
            return $this->fail('Billing cycle tidak valid untuk plan ini.', ['code' => 'INVALID_BILLING_CYCLE'], 422);
        }

        $paymentFlow = (string) $validated['payment_flow'];
        if ($paymentFlow === 'wallet' && !$this->memberWalletEnabled()) {
            return $this->fail('Fitur wallet/e-wallet untuk member sedang dimatikan oleh owner.', [
                'code' => 'MEMBER_WALLET_DISABLED',
            ], 422);
        }

        $checkoutMode = $paymentFlow === 'wallet' ? 'wallet_instant' : $this->checkoutMode();
        $gatewayProvider = $this->activeGatewayProvider();
        $gatewayReady = $this->isActiveGatewayReady();
        $manualPaymentMethod = isset($validated['manual_payment_method']) ? (string) $validated['manual_payment_method'] : null;
        $manualPaymentOptions = $this->manualPaymentSettings()->publicOptions();

        // If manual payment is enabled, force manual confirmation mode
        if ($paymentFlow === 'direct' && $manualPaymentOptions['enabled'] && count($manualPaymentOptions['methods']) > 0) {
            $checkoutMode = 'manual_confirmation';
        }

        if ($paymentFlow === 'direct' && $checkoutMode === 'manual_confirmation') {
            if (!$manualPaymentOptions['enabled'] || count($manualPaymentOptions['methods']) === 0) {
                return $this->fail('Manual payment belum dikonfigurasi owner.', ['code' => 'MANUAL_PAYMENT_NOT_READY'], 422);
            }

            if ($manualPaymentMethod === null) {
                return $this->fail('Pilih metode pembayaran manual terlebih dahulu.', ['code' => 'MANUAL_PAYMENT_METHOD_REQUIRED'], 422);
            }
        }

        if ($paymentFlow === 'direct' && $checkoutMode === 'gateway_automatic' && !$gatewayReady) {
            return $this->fail($this->activeGatewayLabel() . ' automatic payment belum siap. Aktifkan mode manual confirmation atau lengkapi konfigurasi gateway aktif.', [
                'code' => strtoupper($gatewayProvider) . '_NOT_READY',
            ], 422);
        }

        $result = DB::transaction(function () use ($organizationId, $user, $app, $plan, $paymentFlow, $checkoutMode, $gatewayProvider, $billingCycle, $manualPaymentMethod) {
            $amount = $this->resolvePlanAmount($plan, $billingCycle);
            $subscriptionStatus = $paymentFlow === 'wallet' ? 'draft' : 'pending_payment';
            $intentStatus = match ($paymentFlow) {
                'wallet' => 'pending',
                default => $checkoutMode === 'manual_confirmation' ? 'manual_review' : 'gateway_pending',
            };

            $subscription = Subscription::query()->create([
                'organization_id' => $organizationId,
                'app_id' => $app->id,
                'plan_id' => $plan->id,
                'status' => $subscriptionStatus,
                'amount' => $amount,
                'currency' => 'IDR',
                'billing_cycle' => $billingCycle,
                'starts_at' => null,
                'ends_at' => null,
                'metadata' => [
                    'checkout_mode' => $checkoutMode,
                    'payment_flow' => $paymentFlow,
                    'created_by_user_id' => (int) $user->id,
                    'manual_payment_method' => $manualPaymentMethod,
                ],
            ]);

            $intentToken = Str::upper('chk_' . Str::random(24));
            $intent = CheckoutIntent::query()->create([
                'organization_id' => $organizationId,
                'user_id' => (int) $user->id,
                'app_id' => $app->id,
                'plan_id' => $plan->id,
                'subscription_id' => $subscription->id,
                'intent_token' => $intentToken,
                'status' => $intentStatus,
                'amount' => $amount,
                'currency' => 'IDR',
                'metadata' => [
                    'checkout_mode' => $checkoutMode,
                    'payment_flow' => $paymentFlow,
                    'app_slug' => $app->slug,
                    'plan_slug' => $plan->slug,
                    'billing_cycle' => $billingCycle,
                    'manual_payment_method' => $manualPaymentMethod,
                ],
            ]);

            $invoice = null;
            if ($paymentFlow === 'direct') {
                $invoice = Invoice::query()->create([
                    'organization_id' => $organizationId,
                    'subscription_id' => $subscription->id,
                    'invoice_number' => 'INV-' . strtoupper(date('Ymd')) . '-' . strtoupper(Str::random(6)),
                    'status' => $checkoutMode === 'manual_confirmation' ? 'issued' : 'draft',
                    'amount' => $amount,
                    'tax' => 0,
                    'total' => $amount,
                    'currency' => 'IDR',
                    'line_items' => [
                        [
                            'description' => "{$app->name} - {$plan->name} ({$billingCycle})",
                            'amount' => $amount,
                            'discount' => 0,
                        ],
                    ],
                    'issued_at' => now(),
                    'due_at' => now()->addDay(),
                    'paid_at' => null,
                    'metadata' => [
                        'provider' => $checkoutMode === 'manual_confirmation' ? 'manual_confirmation' : $gatewayProvider,
                        'intent_token' => $intentToken,
                        'manual_payment_method' => $manualPaymentMethod,
                        'billing_cycle' => $billingCycle,
                    ],
                ]);
            }

            return compact('subscription', 'intent', 'invoice');
        });

        /** @var CheckoutIntent $intent */
        $intent = $result['intent'];
        /** @var Subscription $subscription */
        $subscription = $result['subscription'];
        /** @var Invoice|null $invoice */
        $invoice = $result['invoice'];

        $paymentUrl = null;
        $paymentSessionId = null;

        if ($paymentFlow === 'direct' && $checkoutMode === 'gateway_automatic') {
            try {
                if ($gatewayProvider === 'ipaymu') {
                    $session = $this->ipaymu()->createRedirectPayment([
                        'product' => ["{$app->name} - {$plan->name}"],
                        'qty' => [1],
                        'price' => [(int) $plan->price],
                        'paymentMethod' => $this->ipaymuSettings()->enabledPaymentMethods(),
                        'referenceId' => (string) $intent->intent_token,
                        'description' => ["Aktivasi {$app->name} - {$plan->name}"],
                        'buyerName' => (string) $user->name,
                        'buyerEmail' => (string) $user->email,
                        'notifyUrl' => $this->ipaymuNotifyUrl([
                            'purpose' => 'subscription_checkout',
                            'organization_id' => $organizationId,
                            'subscription_id' => (int) $subscription->id,
                            'checkout_intent_id' => (int) $intent->id,
                            'invoice_id' => (int) ($invoice?->id ?? 0),
                            'reference_id' => (string) $intent->intent_token,
                        ]),
                        // Browser redirect back to the app so we can reconcile even when the
                        // server-to-server webhook can't reach us (e.g. local/sandbox).
                        'returnUrl' => $this->checkoutReturnUrl($request, (string) $intent->intent_token),
                        'cancelUrl' => $this->checkoutReturnUrl($request, (string) $intent->intent_token, true),
                    ]);

                    $paymentUrl = (string) (data_get($session, 'Data.Url') ?: data_get($session, 'Url') ?: '');
                    $paymentSessionId = (string) (data_get($session, 'Data.SessionID') ?: data_get($session, 'Data.TransactionId') ?: '');

                    $intentMeta = is_array($intent->metadata) ? $intent->metadata : [];
                    $intentMeta['ipaymu'] = array_filter([
                        'payment_session_id' => $paymentSessionId,
                        'payment_url' => $paymentUrl,
                    ]);
                    $intent->forceFill([
                        'metadata' => $intentMeta,
                    ])->save();

                    if ($invoice) {
                        $invoiceMeta = is_array($invoice->metadata) ? $invoice->metadata : [];
                        $invoiceMeta['ipaymu'] = array_filter([
                            'payment_session_id' => $paymentSessionId,
                            'payment_url' => $paymentUrl,
                        ]);
                        $invoice->forceFill([
                            'status' => 'issued',
                            'metadata' => $invoiceMeta,
                        ])->save();
                    }
                } elseif ($gatewayProvider === 'doku') {
                    $session = $this->doku()->createCheckout([
                        'order' => [
                            'amount' => (int) $intent->amount,
                            'invoice_number' => (string) ($invoice?->invoice_number ?? $intent->intent_token),
                            'currency' => 'IDR',
                            'callback_url' => url('/hellom/dashboard/payments'),
                            'callback_url_result' => url('/hellom/dashboard/payments'),
                            'language' => 'ID',
                            'auto_redirect' => false,
                            'line_items' => [
                                [
                                    'name' => "{$app->name} - {$plan->name}",
                                    'price' => (int) $intent->amount,
                                    'quantity' => 1,
                                ],
                            ],
                        ],
                        'payment' => [
                            'payment_due_date' => 1440,
                            'payment_method_types' => $this->dokuSettings()->getConfig()['payment_method_types'],
                        ],
                        'customer' => [
                            'name' => (string) $user->name,
                            'email' => (string) $user->email,
                        ],
                        'additional_info' => [
                            'override_notification_url' => $this->dokuNotifyUrl(),
                        ],
                    ]);

                    $paymentUrl = (string) data_get($session, 'response.payment.url', '');
                    $paymentSessionId = (string) data_get($session, 'response.order.session_id', '');

                    $intentMeta = is_array($intent->metadata) ? $intent->metadata : [];
                    $intentMeta['doku'] = [
                        'payment_session_id' => $paymentSessionId,
                        'payment_url' => $paymentUrl,
                        'invoice_number' => (string) ($invoice?->invoice_number ?? ''),
                        'payment_method_types' => $this->dokuSettings()->getConfig()['payment_method_types'],
                    ];
                    $intent->forceFill([
                        'metadata' => $intentMeta,
                    ])->save();

                    if ($invoice) {
                        $invoiceMeta = is_array($invoice->metadata) ? $invoice->metadata : [];
                        $invoiceMeta['doku'] = $intentMeta['doku'];
                        $invoice->forceFill([
                            'status' => 'issued',
                            'metadata' => $invoiceMeta,
                        ])->save();
                    }
                } else {
                    $session = $this->xendit()->createPaymentSession([
                        'reference_id' => (string) $intent->intent_token,
                        'session_type' => 'PAY',
                        'mode' => 'PAYMENT_LINK',
                        'amount' => (int) $intent->amount,
                        'currency' => 'IDR',
                        'country' => 'ID',
                        'locale' => 'id',
                        'capture_method' => 'AUTOMATIC',
                        'allow_save_payment_method' => 'DISABLED',
                        'description' => "Aktivasi {$app->name} - {$plan->name}",
                        'items' => [
                            [
                                'reference_id' => (string) $plan->slug,
                                'type' => 'DIGITAL_SERVICE',
                                'name' => "{$app->name} - {$plan->name}",
                                'net_unit_amount' => (int) $plan->price,
                                'quantity' => 1,
                                'category' => 'SAAS',
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
                            'purpose' => 'subscription_checkout',
                            'organization_id' => $organizationId,
                            'subscription_id' => (int) $subscription->id,
                            'checkout_intent_id' => (int) $intent->id,
                            'invoice_id' => (int) ($invoice?->id ?? 0),
                            'app_slug' => (string) $app->slug,
                            'plan_slug' => (string) $plan->slug,
                        ],
                    ]);

                    $paymentUrl = (string) data_get($session, 'payment_link_url', '');
                    $paymentSessionId = (string) data_get($session, 'payment_session_id', '');

                    $intentMeta = is_array($intent->metadata) ? $intent->metadata : [];
                    $intentMeta['xendit'] = [
                        'payment_session_id' => $paymentSessionId,
                        'payment_link_url' => $paymentUrl,
                    ];
                    $intent->forceFill([
                        'metadata' => $intentMeta,
                    ])->save();

                    if ($invoice) {
                        $invoiceMeta = is_array($invoice->metadata) ? $invoice->metadata : [];
                        $invoiceMeta['xendit'] = [
                            'payment_session_id' => $paymentSessionId,
                            'payment_link_url' => $paymentUrl,
                        ];
                        $invoice->forceFill([
                            'status' => 'issued',
                            'metadata' => $invoiceMeta,
                        ])->save();
                    }
                }
            } catch (\Throwable $exception) {
                return $this->fail($exception->getMessage(), [
                    'code' => strtoupper($gatewayProvider) . '_CHECKOUT_CREATE_FAILED',
                    'intent_token' => (string) $intent->intent_token,
                ], 422);
            }
        }

        if ($paymentFlow === 'direct') {
            $freshIntent = $intent->fresh(['subscription.organization.users', 'app', 'plan', 'user']);
            if ($freshIntent instanceof CheckoutIntent) {
                $this->sendCheckoutStartedNotifications($freshIntent, $invoice, $paymentUrl);
            }

            // Create notification for manual confirmation checkouts
            if ($checkoutMode === 'manual_confirmation') {
                $this->notificationService->createManualConfirmationNotif($intent);
            }
        }

        return $this->ok([
            'checkout_intent' => [
                'id' => (int) $intent->id,
                'intent_token' => (string) $intent->intent_token,
                'status' => (string) $intent->status,
                'amount' => (int) $intent->amount,
                'currency' => (string) $intent->currency,
            ],
            'subscription_draft' => [
                'id' => (int) $subscription->id,
                'status' => (string) $subscription->status,
                'app_slug' => (string) $app->slug,
                'plan_slug' => (string) $plan->slug,
                'amount' => (int) $subscription->amount,
                'currency' => (string) $subscription->currency,
            ],
            'payment' => [
                'flow' => $paymentFlow,
                'checkout_mode' => $checkoutMode,
                'requires_manual_confirmation' => $paymentFlow === 'direct' && $checkoutMode === 'manual_confirmation',
                'provider' => $paymentFlow === 'wallet' ? 'wallet' : ($checkoutMode === 'manual_confirmation' ? 'manual_confirmation' : $gatewayProvider),
                'invoice_id' => $invoice?->id,
                'invoice_number' => $invoice?->invoice_number,
                'payment_url' => $paymentUrl !== '' ? $paymentUrl : null,
                'payment_session_id' => $paymentSessionId !== '' ? $paymentSessionId : null,
                'manual_payment_method' => $paymentFlow === 'direct' && $checkoutMode === 'manual_confirmation'
                    ? (string) data_get($intent->metadata, 'manual_payment_method', '')
                    : null,
                'manual_payment_options' => $paymentFlow === 'direct' && $checkoutMode === 'manual_confirmation'
                    ? $this->manualPaymentSettings()->publicOptions()
                    : null,
            ],
            'next_step' => [
                'action' => $paymentFlow === 'wallet'
                    ? 'confirm_wallet'
                    : ($checkoutMode === 'manual_confirmation' ? 'await_owner_confirmation' : 'await_gateway_payment'),
            ],
        ], 'Checkout started');
    }

    public function walletTopupSession(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        if (!$this->memberWalletEnabled()) {
            return $this->fail('Fitur wallet/e-wallet untuk member sedang dimatikan oleh owner.', [
                'code' => 'MEMBER_WALLET_DISABLED',
            ], 422);
        }

        if (!$this->isActiveGatewayReady()) {
            return $this->fail($this->activeGatewayLabel() . ' belum siap. Lengkapi kredensial gateway aktif terlebih dahulu.', [
                'code' => strtoupper($this->activeGatewayProvider()) . '_NOT_READY',
            ], 422);
        }

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:10000'],
            'channel' => ['nullable', 'string', 'max:50'],
        ]);

        $channel = strtolower((string) ($validated['channel'] ?? 'qris'));
        $referenceId = 'topup_' . Str::upper(Str::random(18));

        try {
            if ($this->activeGatewayProvider() === 'ipaymu') {
                $session = $this->ipaymu()->createRedirectPayment([
                    'product' => ['Top up wallet Hellom'],
                    'qty' => [1],
                    'price' => [(int) $validated['amount']],
                    'paymentMethod' => $this->ipaymuSettings()->enabledPaymentMethods(),
                    'referenceId' => $referenceId,
                    'description' => ['Top up saldo wallet Hellom'],
                    'buyerName' => (string) $user->name,
                    'buyerEmail' => (string) $user->email,
                    'notifyUrl' => $this->ipaymuNotifyUrl([
                        'purpose' => 'wallet_topup',
                        'organization_id' => $organizationId,
                        'user_id' => (int) $user->id,
                        'reference_id' => $referenceId,
                        'channel' => $channel,
                    ]),
                ]);
            } elseif ($this->activeGatewayProvider() === 'doku') {
                $session = $this->doku()->createCheckout([
                    'order' => [
                        'amount' => (int) $validated['amount'],
                        'invoice_number' => $referenceId,
                        'currency' => 'IDR',
                        'callback_url' => url('/hellom/dashboard/payments'),
                        'callback_url_result' => url('/hellom/dashboard/payments'),
                        'language' => 'ID',
                        'auto_redirect' => false,
                        'line_items' => [
                            [
                                'name' => 'Top up wallet Hellom',
                                'price' => (int) $validated['amount'],
                                'quantity' => 1,
                            ],
                        ],
                    ],
                    'payment' => [
                        'payment_due_date' => 1440,
                        'payment_method_types' => $this->dokuSettings()->getConfig()['payment_method_types'],
                    ],
                    'customer' => [
                        'name' => (string) $user->name,
                        'email' => (string) $user->email,
                    ],
                    'additional_info' => [
                        'override_notification_url' => $this->dokuNotifyUrl(),
                    ],
                ]);
            } else {
                $configuredVaChannels = $this->xenditSettings()->getConfig()['va_channels'] ?? [];
                $allowedChannels = match ($channel) {
                    'va' => $configuredVaChannels,
                    default => ['QRIS'],
                };

                $payload = [
                    'reference_id' => $referenceId,
                    'session_type' => 'PAY',
                    'mode' => 'PAYMENT_LINK',
                    'amount' => (int) $validated['amount'],
                    'currency' => 'IDR',
                    'country' => 'ID',
                    'locale' => 'id',
                    'capture_method' => 'AUTOMATIC',
                    'allow_save_payment_method' => 'DISABLED',
                    'description' => 'Top up saldo wallet Hellom',
                    'items' => [
                        [
                            'reference_id' => 'wallet_topup',
                            'type' => 'DIGITAL_SERVICE',
                            'name' => 'Top up wallet Hellom',
                            'net_unit_amount' => (int) $validated['amount'],
                            'quantity' => 1,
                            'category' => 'WALLET',
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
                        'purpose' => 'wallet_topup',
                        'organization_id' => $organizationId,
                        'user_id' => (int) $user->id,
                        'channel' => $channel,
                    ],
                ];

                if ($allowedChannels !== []) {
                    $payload['allowed_payment_channels'] = $allowedChannels;
                }

                $session = $this->xendit()->createPaymentSession($payload);
            }
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage(), [
                'code' => strtoupper($this->activeGatewayProvider()) . '_TOPUP_SESSION_FAILED',
            ], 422);
        }

        return $this->ok([
            'reference_id' => $referenceId,
            'provider' => $this->activeGatewayProvider(),
            'payment_session_id' => (string) (
                data_get($session, 'payment_session_id')
                ?: data_get($session, 'Data.SessionID')
                ?: data_get($session, 'Data.TransactionId')
                ?: data_get($session, 'response.order.session_id')
                ?: ''
            ),
            'payment_url' => (string) (
                data_get($session, 'payment_link_url')
                ?: data_get($session, 'Data.Url')
                ?: data_get($session, 'Url')
                ?: data_get($session, 'response.payment.url')
                ?: ''
            ),
            'amount' => (int) $validated['amount'],
            'channel' => $channel,
        ], 'Wallet top-up session created');
    }

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

    public function adminPendingCheckouts(Request $request): JsonResponse
    {
        $limit = max(1, min((int) ($request->query('limit') ?: 50), 100));

        $items = CheckoutIntent::query()
            ->with([
                'user:id,name,email',
                'app:id,name,slug',
                'plan:id,name,slug,type',
                'subscription:id,organization_id,status',
                'organization:id,name,slug',
            ])
            ->whereIn('status', ['manual_review', 'awaiting_manual_review'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->ok([
            'items' => $items->map(function (CheckoutIntent $intent): array {
                return [
                    'id' => (int) $intent->id,
                    'intent_token' => (string) $intent->intent_token,
                    'status' => (string) $intent->status,
                    'amount' => (int) $intent->amount,
                    'currency' => (string) $intent->currency,
                    'created_at' => $intent->created_at,
                    'organization' => [
                        'id' => (int) ($intent->organization?->id ?? 0),
                        'name' => (string) ($intent->organization?->name ?? ''),
                    ],
                    'user' => [
                        'id' => (int) ($intent->user?->id ?? 0),
                        'name' => (string) ($intent->user?->name ?? ''),
                        'email' => (string) ($intent->user?->email ?? ''),
                    ],
                    'app' => [
                        'slug' => (string) ($intent->app?->slug ?? ''),
                        'name' => (string) ($intent->app?->name ?? ''),
                    ],
                    'plan' => [
                        'slug' => (string) ($intent->plan?->slug ?? ''),
                        'name' => (string) ($intent->plan?->name ?? ''),
                    ],
                    'manual_payment_method' => (string) data_get($intent->metadata, 'manual_payment_method', ''),
                ];
            })->values(),
        ], 'Pending manual checkouts');
    }

    public function adminApproveManualCheckout(Request $request, int $intentId): JsonResponse
    {
        $intent = CheckoutIntent::query()
            ->with(['subscription', 'app', 'plan'])
            ->find($intentId);

        if (!$intent) {
            return $this->fail('Checkout intent not found', ['code' => 'INTENT_NOT_FOUND'], 404);
        }

        if (!in_array((string) $intent->status, ['manual_review', 'awaiting_manual_review'], true)) {
            return $this->fail('Checkout intent is not awaiting manual review', ['code' => 'INTENT_NOT_REVIEWABLE'], 422);
        }

        DB::transaction(function () use ($intent): void {
            $now = now();

            $intent->forceFill([
                'status' => 'confirmed',
            ])->save();

            if ($intent->subscription) {
                $subMeta = is_array($intent->subscription->metadata) ? $intent->subscription->metadata : [];
                $subMeta['activation_source'] = 'manual_confirmation';
                $subMeta['manual_confirmed_at'] = $now->toISOString();

                $intent->subscription->forceFill([
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => $this->resolveSubscriptionEndAt($intent->subscription->plan, $now),
                    'metadata' => $subMeta,
                ])->save();
            }

            Entitlement::query()->updateOrCreate(
                [
                    'organization_id' => (int) $intent->organization_id,
                    'app_id' => (int) $intent->app_id,
                ],
                [
                    'plan_id' => (int) $intent->plan_id,
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => null,
                ]
            );

            if ((int) $intent->amount > 0) {
                \App\Models\PlatformFinanceLedger::recordRevenue(
                    'manual_subscription_payment',
                    (int) $intent->amount,
                    (int) $intent->organization_id,
                    'checkout_intents',
                    (int) $intent->id,
                    'Manual subscription checkout approved by admin'
                );
            }

            if ($intent->subscription && (int) $intent->amount > 0) {
                InvoiceController::generateFromCheckout(
                    organizationId: (int) $intent->organization_id,
                    subscriptionId: (int) $intent->subscription->id,
                    amount: (int) $intent->amount,
                    discount: 0,
                    appSlug: (string) ($intent->app?->slug ?? ''),
                    planSlug: (string) ($intent->plan?->slug ?? ''),
                    paymentMethod: 'manual_confirmation',
                );
            }

            $this->ensurePosProvisioning((string) ($intent->app?->slug ?? ''), (int) $intent->organization_id);
        });

        $freshIntent = $intent->fresh(['subscription.organization.users', 'app', 'plan', 'user']);
        if ($freshIntent instanceof CheckoutIntent) {
            $this->sendCheckoutDecisionNotifications($freshIntent, true);
        }

        return $this->ok([
            'intent_id' => (int) $intent->id,
            'status' => 'confirmed',
        ], 'Manual checkout approved');
    }

    public function adminRejectManualCheckout(Request $request, int $intentId): JsonResponse
    {
        $intent = CheckoutIntent::query()
            ->with('subscription')
            ->find($intentId);

        if (!$intent) {
            return $this->fail('Checkout intent not found', ['code' => 'INTENT_NOT_FOUND'], 404);
        }

        if (!in_array((string) $intent->status, ['manual_review', 'awaiting_manual_review'], true)) {
            return $this->fail('Checkout intent is not awaiting manual review', ['code' => 'INTENT_NOT_REVIEWABLE'], 422);
        }

        DB::transaction(function () use ($intent): void {
            $intent->forceFill([
                'status' => 'rejected',
            ])->save();

            if ($intent->subscription) {
                $intent->subscription->forceFill([
                    'status' => 'cancelled',
                ])->save();
            }
        });

        $freshIntent = $intent->fresh(['subscription.organization.users', 'app', 'plan', 'user']);
        if ($freshIntent instanceof CheckoutIntent) {
            $this->sendCheckoutDecisionNotifications($freshIntent, false);
        }

        return $this->ok([
            'intent_id' => (int) $intent->id,
            'status' => 'rejected',
        ], 'Manual checkout rejected');
    }

    public function setSubscriptionWalletAutoRenew(Request $request, int $subscriptionId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $subscription = Subscription::query()
            ->with(['app', 'plan'])
            ->where('id', $subscriptionId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$subscription) {
            return $this->fail('Subscription not found', ['code' => 'SUBSCRIPTION_NOT_FOUND'], 404);
        }

        $enabled = (bool) $validated['enabled'];
        $meta = is_array($subscription->metadata) ? $subscription->metadata : [];
        $meta['wallet_auto_renew'] = $enabled;
        $meta['wallet_auto_renew_updated_by_user_id'] = (int) $user->id;
        $meta['wallet_auto_renew_updated_at'] = now()->toISOString();

        $subscription->forceFill([
            'metadata' => $meta,
        ])->save();

        return $this->ok([
            'subscription' => [
                'id' => (int) $subscription->id,
                'status' => (string) $subscription->status,
                'app_slug' => (string) ($subscription->app?->slug ?? ''),
                'plan_slug' => (string) ($subscription->plan?->slug ?? ''),
                'wallet_auto_renew' => $enabled,
            ],
        ], 'Subscription wallet auto-renew updated');
    }

    public function walletTopupMock(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:10000'],
            'source' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $result = DB::transaction(function () use ($organizationId, $user, $validated) {
            $wallet = OrganizationWallet::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (!$wallet instanceof OrganizationWallet) {
                $wallet = OrganizationWallet::query()->create([
                    'organization_id' => $organizationId,
                    'currency' => 'IDR',
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'total_in' => 0,
                    'total_out' => 0,
                    'status' => 'active',
                ]);
            }

            $amount = (int) $validated['amount'];
            $externalRef = 'topup_' . Str::upper(Str::random(16));

            $wallet->forceFill([
                'available_balance' => (int) $wallet->available_balance + $amount,
                'total_in' => (int) $wallet->total_in + $amount,
            ])->save();

            $transaction = OrganizationWalletTransaction::query()->create([
                'organization_id' => $organizationId,
                'wallet_id' => (int) $wallet->id,
                'user_id' => (int) $user->id,
                'type' => 'wallet_topup_mock',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => (int) $wallet->available_balance,
                'reference_type' => 'billing_wallet_topup',
                'reference_id' => $externalRef,
                'external_ref' => $externalRef,
                'description' => 'Wallet top-up (mock)',
                'metadata' => [
                    'source' => isset($validated['source']) ? (string) $validated['source'] : 'manual',
                    'notes' => isset($validated['notes']) ? (string) $validated['notes'] : null,
                ],
            ]);

            return [
                'wallet' => $wallet->fresh(),
                'transaction' => $transaction,
            ];
        });

        return $this->ok([
            'wallet' => $this->walletPayload($result['wallet']),
            'topup' => $this->walletTransactionPayload($result['transaction']),
        ], 'Wallet top-up success (mock)', 201);
    }

    public function walletAutoRenewPreview(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'include_overdue' => ['nullable', 'boolean'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $limit = (int) ($validated['limit'] ?? 100);
        $includeOverdue = (bool) ($validated['include_overdue'] ?? true);

        $now = now();
        $horizon = $now->copy()->addDays($days);

        $query = Subscription::query()
            ->with(['app:id,slug,name', 'plan:id,slug,name'])
            ->where('organization_id', $organizationId)
            ->where('billing_cycle', 'monthly')
            ->whereIn('status', ['active', 'failed'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $horizon)
            ->orderBy('ends_at')
            ->orderBy('id')
            ->limit($limit);

        if (!$includeOverdue) {
            $query->where('ends_at', '>', $now);
        }

        $subscriptions = $query->get();

        $wallet = OrganizationWallet::query()->where('organization_id', $organizationId)->first();
        $availableBalance = (int) ($wallet?->available_balance ?? 0);
        $runningBalance = $availableBalance;

        $totalDueAmount = 0;
        $payableAmount = 0;
        $unpayableAmount = 0;
        $overdueCount = 0;
        $upcomingCount = 0;

        $items = $subscriptions->map(function (Subscription $subscription) use (&$runningBalance, &$totalDueAmount, &$payableAmount, &$unpayableAmount, &$overdueCount, &$upcomingCount, $now): array {
            $amount = (int) $subscription->amount;
            $autoRenewEnabled = (bool) data_get($subscription->metadata, 'wallet_auto_renew', true);
            $isOverdue = $subscription->ends_at?->lte($now) ?? false;

            if ($isOverdue) {
                $overdueCount++;
            } else {
                $upcomingCount++;
            }

            $totalDueAmount += max(0, $amount);

            $canAutoCharge = false;
            $reason = null;
            $requiredTopup = 0;

            if (!$autoRenewEnabled) {
                $reason = 'auto_renew_disabled';
            } elseif ($amount < 0) {
                $reason = 'invalid_amount';
            } elseif ($amount === 0) {
                $canAutoCharge = true;
            } elseif ($runningBalance >= $amount) {
                $canAutoCharge = true;
                $runningBalance -= $amount;
            } else {
                $reason = 'insufficient_balance';
                $requiredTopup = $amount - $runningBalance;
            }

            if ($canAutoCharge) {
                $payableAmount += max(0, $amount);
            } else {
                $unpayableAmount += max(0, $amount);
            }

            return [
                'subscription_id' => (int) $subscription->id,
                'status' => (string) $subscription->status,
                'amount' => $amount,
                'currency' => (string) $subscription->currency,
                'billing_cycle' => (string) $subscription->billing_cycle,
                'ends_at' => $subscription->ends_at,
                'due_state' => $isOverdue ? 'overdue' : 'upcoming',
                'wallet_auto_renew' => $autoRenewEnabled,
                'can_auto_charge' => $canAutoCharge,
                'reason' => $reason,
                'required_topup' => $requiredTopup,
                'app' => [
                    'slug' => (string) ($subscription->app?->slug ?? ''),
                    'name' => (string) ($subscription->app?->name ?? ''),
                ],
                'plan' => [
                    'slug' => (string) ($subscription->plan?->slug ?? ''),
                    'name' => (string) ($subscription->plan?->name ?? ''),
                ],
            ];
        })->values();

        return $this->ok([
            'organization_id' => $organizationId,
            'filters' => [
                'days' => $days,
                'limit' => $limit,
                'include_overdue' => $includeOverdue,
                'horizon_at' => $horizon,
            ],
            'wallet' => $wallet ? $this->walletPayload($wallet) : [
                'id' => null,
                'organization_id' => $organizationId,
                'currency' => 'IDR',
                'available_balance' => 0,
                'pending_balance' => 0,
                'total_in' => 0,
                'total_out' => 0,
                'status' => 'active',
                'updated_at' => null,
            ],
            'summary' => [
                'total_due_count' => (int) $subscriptions->count(),
                'overdue_count' => $overdueCount,
                'upcoming_count' => $upcomingCount,
                'total_due_amount' => $totalDueAmount,
                'payable_amount' => $payableAmount,
                'unpayable_amount' => $unpayableAmount,
                'projected_remaining_balance' => $runningBalance,
                'minimum_topup_required' => max(0, $totalDueAmount - $availableBalance),
            ],
            'items' => $items,
        ], 'Wallet auto-renew preview');
    }

    public function renewSubscriptionWallet(Request $request, int $subscriptionId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $subscription = Subscription::query()
            ->with(['app', 'plan'])
            ->where('id', $subscriptionId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$subscription) {
            return $this->fail('Subscription not found', ['code' => 'SUBSCRIPTION_NOT_FOUND'], 404);
        }

        $result = DB::transaction(function () use ($subscription, $organizationId, $user) {
            $wallet = OrganizationWallet::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (!$wallet instanceof OrganizationWallet) {
                return ['error' => $this->fail('Wallet not found', ['code' => 'WALLET_NOT_FOUND'], 404)];
            }

            $chargeAmount = (int) $subscription->amount;
            if ($chargeAmount < 0) {
                return ['error' => $this->fail('Invalid subscription amount', ['code' => 'INVALID_SUBSCRIPTION_AMOUNT'], 422)];
            }

            if ($chargeAmount > 0 && (int) $wallet->available_balance < $chargeAmount) {
                return ['error' => $this->fail('Insufficient wallet balance for renewal', [
                    'code' => 'INSUFFICIENT_WALLET_BALANCE',
                    'available_balance' => (int) $wallet->available_balance,
                    'required_amount' => $chargeAmount,
                ], 422)];
            }

            if ($chargeAmount > 0) {
                $wallet->forceFill([
                    'available_balance' => (int) $wallet->available_balance - $chargeAmount,
                    'total_out' => (int) $wallet->total_out + $chargeAmount,
                ])->save();
            }

            $externalRef = 'renew_' . Str::upper(Str::random(16));

            if ($chargeAmount > 0) {
                OrganizationWalletTransaction::query()->create([
                    'organization_id' => $organizationId,
                    'wallet_id' => (int) $wallet->id,
                    'user_id' => (int) $user->id,
                    'type' => 'subscription_renew_debit',
                    'direction' => 'debit',
                    'amount' => $chargeAmount,
                    'balance_after' => (int) $wallet->available_balance,
                    'reference_type' => 'subscriptions',
                    'reference_id' => (string) $subscription->id,
                    'external_ref' => $externalRef,
                    'description' => 'Subscription renewal charged from wallet',
                    'metadata' => [
                        'subscription_id' => (int) $subscription->id,
                        'app_slug' => (string) ($subscription->app?->slug ?? ''),
                        'plan_slug' => (string) ($subscription->plan?->slug ?? ''),
                    ],
                ]);
            }

            $now = now();
            $meta = is_array($subscription->metadata) ? $subscription->metadata : [];
            $meta['wallet_last_charge'] = [
                'charged_at' => $now->toISOString(),
                'charged_amount' => $chargeAmount,
                'charged_by_user_id' => (int) $user->id,
                'external_ref' => $externalRef,
            ];

            $subscription->forceFill([
                'status' => 'active',
                'starts_at' => $now,
                'ends_at' => $now->copy()->addMonth(),
                'metadata' => $meta,
            ])->save();

            Entitlement::query()->updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'app_id' => $subscription->app_id,
                ],
                [
                    'plan_id' => $subscription->plan_id,
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => null,
                ]
            );

            $this->ensurePosProvisioning((string) ($subscription->app?->slug ?? ''), $organizationId);

            return [
                'wallet' => $wallet->fresh(),
                'subscription' => $subscription->fresh(['app', 'plan']),
            ];
        });

        if (isset($result['error']) && $result['error'] instanceof JsonResponse) {
            return $result['error'];
        }

        /** @var Subscription $renewed */
        $renewed = $result['subscription'];

        return $this->ok([
            'wallet' => $this->walletPayload($result['wallet']),
            'subscription' => [
                'id' => $renewed->id,
                'status' => (string) $renewed->status,
                'app_slug' => (string) ($renewed->app?->slug ?? ''),
                'plan_slug' => (string) ($renewed->plan?->slug ?? ''),
                'starts_at' => $renewed->starts_at,
                'ends_at' => $renewed->ends_at,
            ],
        ], 'Subscription renewed using wallet');
    }

    public function renewSubscriptionMock(Request $request, int $subscriptionId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $subscription = Subscription::query()
            ->with(['app', 'plan'])
            ->where('id', $subscriptionId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$subscription) {
            return $this->fail('Subscription not found', ['code' => 'SUBSCRIPTION_NOT_FOUND'], 404);
        }

        DB::transaction(function () use ($subscription, $organizationId) {
            $now = now();
            $subscription->forceFill([
                'status' => 'active',
                'starts_at' => $now,
                'ends_at' => $now->copy()->addMonth(),
            ])->save();

            Entitlement::query()->updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'app_id' => $subscription->app_id,
                ],
                [
                    'plan_id' => $subscription->plan_id,
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => null,
                ]
            );

            $this->ensurePosProvisioning((string) ($subscription->app?->slug ?? ''), $organizationId);
        });

        $subscription = $subscription->fresh(['app', 'plan']);
        $entitlement = Entitlement::query()
            ->where('organization_id', $organizationId)
            ->where('app_id', $subscription->app_id)
            ->first();

        return $this->ok([
            'subscription' => [
                'id' => $subscription->id,
                'status' => (string) $subscription->status,
                'app_slug' => (string) ($subscription->app?->slug ?? ''),
                'plan_slug' => (string) ($subscription->plan?->slug ?? ''),
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
            ],
            'entitlement' => [
                'status' => (string) ($entitlement?->status ?? ''),
            ],
        ], 'Subscription renewed/reactivated (mock)');
    }

    public function cancelSubscriptionMock(Request $request, int $subscriptionId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $subscription = Subscription::query()
            ->with(['app', 'plan'])
            ->where('id', $subscriptionId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$subscription) {
            return $this->fail('Subscription not found', ['code' => 'SUBSCRIPTION_NOT_FOUND'], 404);
        }

        if ((string) $subscription->status === 'cancelled') {
            return $this->ok([
                'subscription_id' => $subscription->id,
                'status' => 'already_cancelled',
            ], 'Subscription already cancelled');
        }

        DB::transaction(function () use ($subscription, $organizationId) {
            $now = now();

            $subscription->forceFill([
                'status' => 'cancelled',
                'ends_at' => $now,
            ])->save();

            $appSlug = (string) ($subscription->app?->slug ?? '');
            $entitlementStatus = $appSlug === 'landing_builder' ? 'active' : 'cancelled';

            Entitlement::query()->updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'app_id' => $subscription->app_id,
                ],
                [
                    'plan_id' => $subscription->plan_id,
                    'status' => $entitlementStatus,
                    'starts_at' => null,
                    'ends_at' => $now,
                ]
            );
        });

        $subscription = $subscription->fresh(['app', 'plan']);

        $entitlement = Entitlement::query()
            ->where('organization_id', $organizationId)
            ->where('app_id', $subscription->app_id)
            ->first();

        return $this->ok([
            'subscription' => [
                'id' => $subscription->id,
                'status' => (string) $subscription->status,
                'app_slug' => (string) ($subscription->app?->slug ?? ''),
                'plan_slug' => (string) ($subscription->plan?->slug ?? ''),
                'ends_at' => $subscription->ends_at,
            ],
            'entitlement' => [
                'status' => (string) ($entitlement?->status ?? ''),
            ],
        ], 'Subscription cancelled (mock)');
    }

    public function webhookMockPayment(Request $request): JsonResponse
    {
        $secret = (string) config('payments.mock.webhook_secret', '');
        $signature = (string) $request->header('X-Mock-Signature', '');

        if ($secret === '' || !hash_equals($secret, $signature)) {
            return $this->fail('Invalid webhook signature', ['code' => 'INVALID_WEBHOOK_SIGNATURE'], 401);
        }

        $validated = $request->validate([
            'intent_token' => ['required', 'string'],
            'payment_status' => ['required', 'in:success,failed,expired,cancelled'],
            'provider_ref' => ['nullable', 'string', 'max:100'],
        ]);

        $intent = CheckoutIntent::query()
            ->with(['subscription', 'plan', 'app'])
            ->where('intent_token', (string) $validated['intent_token'])
            ->first();

        if (!$intent) {
            return $this->fail('Checkout intent not found', ['code' => 'INTENT_NOT_FOUND'], 404);
        }

        $paymentStatus = (string) $validated['payment_status'];

        DB::transaction(function () use ($intent, $paymentStatus, $validated) {
            $providerRef = isset($validated['provider_ref']) ? (string) $validated['provider_ref'] : null;
            $intentMeta = is_array($intent->metadata) ? $intent->metadata : [];
            $intentMeta['webhook'] = [
                'payment_status' => $paymentStatus,
                'provider_ref' => $providerRef,
                'processed_at' => now()->toISOString(),
            ];

            if ($paymentStatus === 'success') {
                $now = now();

                $intent->forceFill([
                    'status' => 'confirmed',
                    'metadata' => $intentMeta,
                ])->save();

                if ($intent->subscription) {
                    $intent->subscription->forceFill([
                        'status' => 'active',
                        'starts_at' => $now,
                        'ends_at' => $now->copy()->addMonth(),
                    ])->save();
                }

                Entitlement::query()->updateOrCreate(
                    [
                        'organization_id' => $intent->organization_id,
                        'app_id' => $intent->app_id,
                    ],
                    [
                        'plan_id' => $intent->plan_id,
                        'status' => 'active',
                        'starts_at' => $now,
                        'ends_at' => null,
                    ]
                );

                $this->ensurePosProvisioning((string) ($intent->app?->slug ?? ''), (int) $intent->organization_id);

                return;
            }

            $intentStatus = $paymentStatus === 'failed' ? 'failed' : 'cancelled';
            $subscriptionStatus = $paymentStatus === 'failed' ? 'failed' : 'cancelled';

            $intent->forceFill([
                'status' => $intentStatus,
                'metadata' => $intentMeta,
            ])->save();

            if ($intent->subscription) {
                $intent->subscription->forceFill([
                    'status' => $subscriptionStatus,
                ])->save();
            }
        });

        $intent = $intent->fresh(['subscription', 'app', 'plan', 'user']);

        if ($intent instanceof CheckoutIntent && $intent->user instanceof User) {
            $productName = (string) ($intent->app?->name ?? 'Aplikasi');
            if ($paymentStatus === 'success') {
                $this->notificationService->notifyConsumerPaymentSuccess($intent->user, $intent, $productName);
                if ($intent->subscription instanceof Subscription) {
                    $this->notificationService->notifyConsumerAccessActivated($intent->user, $intent->subscription, $productName);
                }
            } elseif (in_array($paymentStatus, ['expired', 'cancelled'], true)) {
                $this->notificationService->notifyConsumerPaymentPending($intent->user, $intent, $productName);
            } else {
                $this->notificationService->notifyConsumerPaymentFailed($intent->user, $intent, $productName);
            }
        }

        return $this->ok([
            'intent_token' => (string) $intent->intent_token,
            'intent_status' => (string) $intent->status,
            'subscription_status' => (string) ($intent->subscription?->status ?? ''),
            'app_slug' => (string) ($intent->app?->slug ?? ''),
            'plan_slug' => (string) ($intent->plan?->slug ?? ''),
        ], 'Mock payment webhook processed');
    }

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $activeSubscriptions = Subscription::query()
            ->with(['app:id,slug,name', 'plan:id,slug,name,type,price'])
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->get();

        $pendingIntents = CheckoutIntent::query()
            ->with(['app:id,slug,name', 'plan:id,slug,name,type,price'])
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $monthlyTotal = (int) $activeSubscriptions->sum('amount');

        return $this->ok([
            'organization_id' => $organizationId,
            'summary' => [
                'active_subscriptions_count' => $activeSubscriptions->count(),
                'pending_checkout_intents_count' => $pendingIntents->count(),
                'estimated_monthly_total' => $monthlyTotal,
                'currency' => 'IDR',
            ],
            'active_subscriptions' => $activeSubscriptions->map(function (Subscription $subscription): array {
                return [
                    'id' => $subscription->id,
                    'status' => (string) $subscription->status,
                    'amount' => (int) $subscription->amount,
                    'currency' => (string) $subscription->currency,
                    'billing_cycle' => (string) $subscription->billing_cycle,
                    'starts_at' => $subscription->starts_at,
                    'ends_at' => $subscription->ends_at,
                    'app' => [
                        'slug' => (string) ($subscription->app->slug ?? ''),
                        'name' => (string) ($subscription->app->name ?? ''),
                    ],
                    'plan' => [
                        'slug' => (string) ($subscription->plan->slug ?? ''),
                        'name' => (string) ($subscription->plan->name ?? ''),
                        'type' => (string) ($subscription->plan->type ?? ''),
                    ],
                ];
            })->values(),
            'pending_checkout_intents' => $pendingIntents->map(function (CheckoutIntent $intent): array {
                return [
                    'id' => $intent->id,
                    'intent_token' => (string) $intent->intent_token,
                    'status' => (string) $intent->status,
                    'amount' => (int) $intent->amount,
                    'currency' => (string) $intent->currency,
                    'created_at' => $intent->created_at,
                    'app' => [
                        'slug' => (string) ($intent->app->slug ?? ''),
                        'name' => (string) ($intent->app->name ?? ''),
                    ],
                    'plan' => [
                        'slug' => (string) ($intent->plan->slug ?? ''),
                        'name' => (string) ($intent->plan->name ?? ''),
                    ],
                ];
            })->values(),
        ], 'Billing overview');
    }

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $limit = (int) $request->integer('limit', 20);
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $subscriptions = Subscription::query()
            ->with(['app:id,slug,name', 'plan:id,slug,name'])
            ->where('organization_id', $organizationId)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $checkoutIntents = CheckoutIntent::query()
            ->with(['app:id,slug,name', 'plan:id,slug,name'])
            ->where('organization_id', $organizationId)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return $this->ok([
            'organization_id' => $organizationId,
            'subscriptions' => $subscriptions->map(function (Subscription $subscription): array {
                return [
                    'id' => $subscription->id,
                    'status' => (string) $subscription->status,
                    'amount' => (int) $subscription->amount,
                    'currency' => (string) $subscription->currency,
                    'created_at' => $subscription->created_at,
                    'updated_at' => $subscription->updated_at,
                    'app_slug' => (string) ($subscription->app->slug ?? ''),
                    'plan_slug' => (string) ($subscription->plan->slug ?? ''),
                ];
            })->values(),
            'checkout_intents' => $checkoutIntents->map(function (CheckoutIntent $intent): array {
                return [
                    'id' => $intent->id,
                    'intent_token' => (string) $intent->intent_token,
                    'status' => (string) $intent->status,
                    'amount' => (int) $intent->amount,
                    'currency' => (string) $intent->currency,
                    'created_at' => $intent->created_at,
                    'updated_at' => $intent->updated_at,
                    'app_slug' => (string) ($intent->app->slug ?? ''),
                    'plan_slug' => (string) ($intent->plan->slug ?? ''),
                ];
            })->values(),
        ], 'Billing history');
    }

    /**
     * Reconcile a gateway checkout without relying on the inbound webhook:
     * verify the payment directly with iPaymu, then activate access + notify owner.
     * Safe to call repeatedly (idempotent) — used by polling and the return redirect.
     */
    public function reconcileCheckout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'intent_token' => ['required', 'string'],
            'transaction_id' => ['nullable', 'string'],
        ]);

        $intent = CheckoutIntent::query()
            ->with(['subscription.plan', 'app', 'plan', 'user'])
            ->where('intent_token', (string) $validated['intent_token'])
            ->first();

        if (!$intent instanceof CheckoutIntent) {
            return $this->fail('Checkout tidak ditemukan.', ['code' => 'NOT_FOUND'], 404);
        }

        $user = $request->user();
        if ($user && (int) $intent->user_id > 0 && (int) $intent->user_id !== (int) $user->id) {
            return $this->fail('Tidak diizinkan.', ['code' => 'FORBIDDEN'], 403);
        }

        $activator = app(SubscriptionCheckoutActivationService::class);

        if (in_array((string) $intent->status, ['confirmed', 'paid'], true)) {
            $activator->ensureActiveAccessForConfirmedCheckout($intent);

            return $this->ok(['active' => true, 'status' => 'confirmed'], 'Akses sudah aktif.');
        }

        $meta = is_array($intent->metadata) ? $intent->metadata : [];
        $ipaymuMeta = is_array($meta['ipaymu'] ?? null) ? $meta['ipaymu'] : [];
        $transactionId = trim((string) ($validated['transaction_id'] ?? ''))
            ?: trim((string) ($ipaymuMeta['transaction_id'] ?? ''))
            ?: trim((string) ($ipaymuMeta['payment_session_id'] ?? ''));

        if ($transactionId === '') {
            return $this->ok(['active' => false, 'status' => 'pending'], 'Menunggu pembayaran.');
        }

        try {
            $result = $this->ipaymu()->checkTransaction($transactionId);
            $data = (array) ($result['Data'] ?? $result['data'] ?? []);
            $statusCode = $data['Status'] ?? $data['StatusCode'] ?? null;
            $statusDesc = strtolower((string) ($data['StatusDesc'] ?? $data['StatusDescription'] ?? ''));
            $paid = (int) $statusCode === 1
                || in_array($statusDesc, ['berhasil', 'success', 'paid', 'settled', 'settlement'], true);

            if ($paid) {
                $activator->confirmGatewayCheckout($intent, [
                    'transaction_id' => $transactionId,
                    'invoice_id' => (int) ($meta['invoice_id'] ?? 0),
                    'reconciled' => true,
                ], 'iPaymu');

                return $this->ok(['active' => true, 'status' => 'confirmed'], 'Pembayaran dikonfirmasi.');
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $this->ok(['active' => false, 'status' => 'pending'], 'Menunggu konfirmasi pembayaran.');
    }

    public function checkoutIntentMock(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'plan_slug' => ['required', 'string'],
        ]);

        $app = AppCatalog::query()
            ->where('slug', (string) $validated['app_slug'])
            ->where('is_active', true)
            ->first();

        if (!$app) {
            return $this->fail('App not found', ['code' => 'APP_NOT_FOUND'], 404);
        }

        $plan = Plan::query()
            ->where('slug', (string) $validated['plan_slug'])
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            return $this->fail('Plan not found', ['code' => 'PLAN_NOT_FOUND'], 404);
        }

        if (!$this->planEligibleForApp((string) $plan->slug, (string) $app->slug)) {
            return $this->fail('Plan is not eligible for selected app', ['code' => 'PLAN_NOT_ELIGIBLE'], 422);
        }

        $result = DB::transaction(function () use ($organizationId, $user, $app, $plan) {
            $subscription = Subscription::query()->create([
                'organization_id' => $organizationId,
                'app_id' => $app->id,
                'plan_id' => $plan->id,
                'status' => 'draft',
                'amount' => (int) $plan->price,
                'currency' => 'IDR',
                'billing_cycle' => 'monthly',
                'starts_at' => null,
                'ends_at' => null,
                'metadata' => [
                    'mode' => 'mock_checkout_intent',
                    'created_by_user_id' => $user->id,
                ],
            ]);

            $intentToken = Str::upper('mock_'.Str::random(24));
            $intent = CheckoutIntent::query()->create([
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'app_id' => $app->id,
                'plan_id' => $plan->id,
                'subscription_id' => $subscription->id,
                'intent_token' => $intentToken,
                'status' => 'pending',
                'amount' => (int) $plan->price,
                'currency' => 'IDR',
                'metadata' => [
                    'mode' => 'mock',
                    'app_slug' => $app->slug,
                    'plan_slug' => $plan->slug,
                ],
            ]);

            $currentEntitlement = Entitlement::query()
                ->with('plan')
                ->where('organization_id', $organizationId)
                ->where('app_id', $app->id)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();

            return [
                'intent' => $intent,
                'subscription' => $subscription,
                'current_entitlement' => $currentEntitlement,
            ];
        });

        /** @var CheckoutIntent $intent */
        $intent = $result['intent'];
        /** @var Subscription $subscription */
        $subscription = $result['subscription'];
        /** @var Entitlement|null $currentEntitlement */
        $currentEntitlement = $result['current_entitlement'];

        return $this->ok([
            'checkout_intent' => [
                'id' => $intent->id,
                'intent_token' => $intent->intent_token,
                'status' => $intent->status,
                'amount' => (int) $intent->amount,
                'currency' => $intent->currency,
            ],
            'subscription_draft' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'app_slug' => (string) $app->slug,
                'plan_slug' => (string) $plan->slug,
                'amount' => (int) $subscription->amount,
                'currency' => $subscription->currency,
            ],
            'current_entitlement' => [
                'status' => (string) ($currentEntitlement?->status ?? 'locked'),
                'plan_slug' => (string) ($currentEntitlement?->plan?->slug ?? ''),
            ],
            'next_step' => [
                'action' => 'mock_payment_confirm',
                'endpoint' => '/api/v1/hellom/billing/checkout-confirm-mock',
                'payload' => [
                    'intent_token' => $intent->intent_token,
                ],
            ],
        ], 'Checkout intent created');
    }

    public function checkoutConfirmMock(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'intent_token' => ['required', 'string'],
        ]);

        $intent = CheckoutIntent::query()
            ->with(['subscription', 'plan', 'app'])
            ->where('intent_token', (string) $validated['intent_token'])
            ->where('organization_id', $organizationId)
            ->first();

        if (!$intent) {
            return $this->fail('Checkout intent not found', ['code' => 'INTENT_NOT_FOUND'], 404);
        }

        if ((string) $intent->status === 'confirmed') {
            return $this->ok([
                'intent_token' => $intent->intent_token,
                'status' => 'already_confirmed',
            ], 'Checkout already confirmed');
        }

        DB::transaction(function () use ($intent) {
            $now = now();

            $intent->forceFill([
                'status' => 'confirmed',
            ])->save();

            if ($intent->subscription) {
                $intent->subscription->forceFill([
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => $now->copy()->addMonth(),
                ])->save();
            }

            Entitlement::query()->updateOrCreate(
                [
                    'organization_id' => $intent->organization_id,
                    'app_id' => $intent->app_id,
                ],
                [
                    'plan_id' => $intent->plan_id,
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => null,
                ]
            );

            $this->ensurePosProvisioning((string) ($intent->app?->slug ?? ''), (int) $intent->organization_id);

            // Generate invoice
            if ((int) $intent->amount > 0 && $intent->subscription) {
                InvoiceController::generateFromCheckout(
                    organizationId: (int) $intent->organization_id,
                    subscriptionId: (int) $intent->subscription->id,
                    amount: (int) $intent->amount,
                    discount: 0,
                    appSlug: (string) ($intent->app?->slug ?? ''),
                    planSlug: (string) ($intent->plan?->slug ?? ''),
                    paymentMethod: 'mock',
                );
            }
        });

        $freshIntent = $intent->fresh(['organization', 'user', 'app', 'plan', 'subscription']);
        if ($freshIntent instanceof CheckoutIntent) {
            $this->notificationService->createGatewayPaymentSuccessNotif($freshIntent, 'Mock');
            if ($freshIntent->user instanceof User && $freshIntent->subscription instanceof Subscription) {
                $productName = (string) ($freshIntent->app?->name ?? 'Aplikasi');
                $this->notificationService->notifyConsumerPaymentSuccess($freshIntent->user, $freshIntent, $productName);
                $this->notificationService->notifyConsumerAccessActivated($freshIntent->user, $freshIntent->subscription, $productName);
            }
        }

        $this->sendSubscriptionBillingNotification((int) ($intent->subscription?->id ?? 0), 'Aktivasi langganan berhasil');

        return $this->ok([
            'intent_token' => $intent->intent_token,
            'intent_status' => 'confirmed',
            'subscription_status' => (string) ($intent->subscription?->fresh()?->status ?? 'active'),
            'app_slug' => (string) ($intent->app?->slug ?? ''),
            'plan_slug' => (string) ($intent->plan?->slug ?? ''),
        ], 'Mock checkout confirmed');
    }

    public function checkoutConfirmWallet(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'intent_token' => ['required', 'string'],
        ]);

        $intent = CheckoutIntent::query()
            ->with(['subscription', 'plan', 'app'])
            ->where('intent_token', (string) $validated['intent_token'])
            ->where('organization_id', $organizationId)
            ->first();

        if (!$intent) {
            return $this->fail('Checkout intent not found', ['code' => 'INTENT_NOT_FOUND'], 404);
        }

        if ((string) $intent->status === 'confirmed') {
            return $this->ok([
                'intent_token' => $intent->intent_token,
                'status' => 'already_confirmed',
            ], 'Checkout already confirmed');
        }

        if ((string) $intent->status !== 'pending') {
            return $this->fail('Only pending checkout can be paid by wallet', ['code' => 'INTENT_NOT_PENDING'], 422);
        }

        $result = DB::transaction(function () use ($intent, $organizationId, $user) {
            $wallet = OrganizationWallet::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (!$wallet instanceof OrganizationWallet) {
                return ['error' => $this->fail('Wallet not found', ['code' => 'WALLET_NOT_FOUND'], 404)];
            }

            $amount = (int) $intent->amount;
            if ($amount < 0) {
                return ['error' => $this->fail('Invalid checkout amount', ['code' => 'INVALID_CHECKOUT_AMOUNT'], 422)];
            }

            if ($amount > 0 && (int) $wallet->available_balance < $amount) {
                return ['error' => $this->fail('Insufficient wallet balance for checkout', [
                    'code' => 'INSUFFICIENT_WALLET_BALANCE',
                    'available_balance' => (int) $wallet->available_balance,
                    'required_amount' => $amount,
                ], 422)];
            }

            if ($amount > 0) {
                $wallet->forceFill([
                    'available_balance' => (int) $wallet->available_balance - $amount,
                    'total_out' => (int) $wallet->total_out + $amount,
                ])->save();
            }

            $externalRef = 'chk_wallet_' . Str::upper(Str::random(14));

            if ($amount > 0) {
                OrganizationWalletTransaction::query()->create([
                    'organization_id' => $organizationId,
                    'wallet_id' => (int) $wallet->id,
                    'user_id' => (int) $user->id,
                    'type' => 'app_checkout_debit',
                    'direction' => 'debit',
                    'amount' => $amount,
                    'balance_after' => (int) $wallet->available_balance,
                    'reference_type' => 'checkout_intents',
                    'reference_id' => (string) $intent->id,
                    'external_ref' => $externalRef,
                    'description' => 'Checkout paid using wallet balance',
                    'metadata' => [
                        'intent_token' => (string) $intent->intent_token,
                        'app_slug' => (string) ($intent->app?->slug ?? ''),
                        'plan_slug' => (string) ($intent->plan?->slug ?? ''),
                    ],
                ]);
            }

            $intentMeta = is_array($intent->metadata) ? $intent->metadata : [];
            $intentMeta['wallet_payment'] = [
                'charged_at' => now()->toISOString(),
                'charged_amount' => $amount,
                'charged_by_user_id' => (int) $user->id,
                'external_ref' => $externalRef,
            ];

            $now = now();
            $intent->forceFill([
                'status' => 'confirmed',
                'metadata' => $intentMeta,
            ])->save();

            if ($intent->subscription) {
                $subMeta = is_array($intent->subscription->metadata) ? $intent->subscription->metadata : [];
                $subMeta['activation_source'] = 'wallet';
                $subMeta['activation_external_ref'] = $externalRef;

                $intent->subscription->forceFill([
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => $now->copy()->addMonth(),
                    'metadata' => $subMeta,
                ])->save();
            }

            Entitlement::query()->updateOrCreate(
                [
                    'organization_id' => $intent->organization_id,
                    'app_id' => $intent->app_id,
                ],
                [
                    'plan_id' => $intent->plan_id,
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => null,
                ]
            );

            $this->ensurePosProvisioning((string) ($intent->app?->slug ?? ''), (int) $intent->organization_id);

            // Generate invoice
            if ($amount > 0) {
                \App\Models\PlatformFinanceLedger::recordRevenue(
                    'wallet_subscription_payment',
                    $amount,
                    (int) $intent->organization_id,
                    'checkout_intents',
                    (int) $intent->id,
                    'Subscription checkout paid using organization wallet'
                );
            }

            if ($amount > 0 && $intent->subscription) {
                InvoiceController::generateFromCheckout(
                    organizationId: (int) $intent->organization_id,
                    subscriptionId: (int) $intent->subscription->id,
                    amount: $amount,
                    discount: 0,
                    appSlug: (string) ($intent->app?->slug ?? ''),
                    planSlug: (string) ($intent->plan?->slug ?? ''),
                    paymentMethod: 'wallet',
                );
            }

            return [
                'wallet' => $wallet->fresh(),
                'intent' => $intent->fresh(['subscription', 'app', 'plan']),
            ];
        });

        if (isset($result['error']) && $result['error'] instanceof JsonResponse) {
            return $result['error'];
        }

        /** @var CheckoutIntent $confirmedIntent */
        $confirmedIntent = $result['intent'];
        $this->sendSubscriptionBillingNotification((int) ($confirmedIntent->subscription?->id ?? 0), 'Pembayaran wallet berhasil');

        return $this->ok([
            'wallet' => $this->walletPayload($result['wallet']),
            'intent_token' => (string) $confirmedIntent->intent_token,
            'intent_status' => (string) $confirmedIntent->status,
            'subscription_status' => (string) ($confirmedIntent->subscription?->status ?? 'active'),
            'app_slug' => (string) ($confirmedIntent->app?->slug ?? ''),
            'plan_slug' => (string) ($confirmedIntent->plan?->slug ?? ''),
        ], 'Checkout confirmed using wallet');
    }

    private function ensurePosProvisioning(string $appSlug, int $organizationId): void
    {
        if ($appSlug !== 'pos') {
            return;
        }

        app(PosProvisioningService::class)->ensureProvisionedForPos($organizationId);
    }

    private function planEligibleForApp(string $planSlug, string $appSlug): bool
    {
        return match ($appSlug) {
            'landing_builder' => $planSlug === 'free',
            'pos' => str_starts_with($planSlug, 'pos_'),
            default => false,
        };
    }

    private function resolveBillingCycle(Plan $plan, ?string $requestedBillingCycle): ?string
    {
        if ($plan->isFree()) {
            return 'lifetime';
        }

        if ($plan->isLifetime()) {
            return 'lifetime';
        }

        if ($requestedBillingCycle !== null) {
            if ($requestedBillingCycle === 'yearly' && $plan->hasBillingCycle(Plan::BILLING_YEARLY)) {
                return 'yearly';
            }

            if ($requestedBillingCycle === 'monthly' && $plan->hasBillingCycle(Plan::BILLING_MONTHLY)) {
                return 'monthly';
            }
        }

        if ($plan->hasBillingCycle(Plan::BILLING_MONTHLY)) {
            return 'monthly';
        }

        if ($plan->hasBillingCycle(Plan::BILLING_YEARLY)) {
            return 'yearly';
        }

        if ($plan->type === Plan::TYPE_ONE_TIME && $plan->duration_days !== null && $plan->duration_days >= 365) {
            return 'yearly';
        }

        return $plan->type === Plan::TYPE_ONE_TIME ? 'lifetime' : null;
    }

    private function resolvePlanAmount(Plan $plan, string $billingCycle): int
    {
        if ($billingCycle === 'lifetime') {
            return (int) $plan->price;
        }

        return (int) $plan->getEffectivePrice($billingCycle);
    }

    private function resolveSubscriptionEndAt(?Plan $plan, \Illuminate\Support\Carbon $startAt): ?\Illuminate\Support\Carbon
    {
        if (!$plan instanceof Plan) {
            return $startAt->copy()->addMonth();
        }

        if ($plan->isLifetime() || $plan->isFree()) {
            return null;
        }

        if ($plan->duration_days) {
            return $startAt->copy()->addDays((int) $plan->duration_days);
        }

        if ($plan->hasBillingCycle(Plan::BILLING_YEARLY)) {
            return $startAt->copy()->addYear();
        }

        return $startAt->copy()->addMonth();
    }

    private function checkoutMode(): string
    {
        return (string) $this->gatewayRuntime()->getRuntimeConfig()['checkout_mode'];
    }

    private function isActiveGatewayReady(): bool
    {
        return (bool) ($this->activeGatewayConfig()['is_ready'] ?? false);
    }

    private function memberWalletEnabled(): bool
    {
        return (bool) $this->gatewayRuntime()->getRuntimeConfig()['member_wallet_enabled'];
    }

    private function activeGatewayProvider(): string
    {
        return (string) $this->gatewayRuntime()->getRuntimeConfig()['active_provider'];
    }

    /**
     * @return array<string,mixed>
     */
    private function activeGatewayConfig(): array
    {
        return $this->activeGatewayProvider() === 'ipaymu'
            ? $this->ipaymuSettings()->getConfig()
            : ($this->activeGatewayProvider() === 'doku'
                ? $this->dokuSettings()->getConfig()
                : $this->xenditSettings()->getConfig());
    }

    private function activeGatewayLabel(): string
    {
        return match ($this->activeGatewayProvider()) {
            'ipaymu' => 'iPaymu',
            'doku' => 'DOKU',
            default => 'Xendit',
        };
    }

    private function providerWebhookPath(string $provider): string
    {
        return match ($provider) {
            'ipaymu' => '/api/v1/hellom/webhooks/ipaymu',
            'doku' => '/api/v1/hellom/webhooks/doku',
            default => '/api/v1/hellom/webhooks/xendit',
        };
    }

    private function providerCallbackTokenConfigured(string $provider): bool
    {
        $config = $provider === 'ipaymu'
            ? $this->ipaymuSettings()->getConfig()
            : ($provider === 'doku'
                ? $this->dokuSettings()->getConfig()
                : $this->xenditSettings()->getConfig());

        return (string) ($config['callback_token'] ?? '') !== '';
    }

    /**
     * @param array<string,int|string> $params
     */
    private function ipaymuNotifyUrl(array $params): string
    {
        $query = array_filter([
            ...$params,
            'token' => (string) $this->ipaymuSettings()->getConfig()['callback_token'],
        ], fn ($value) => $value !== '' && $value !== 0);

        return url($this->providerWebhookPath('ipaymu')) . '?' . http_build_query($query);
    }

    /**
     * Browser return URL for the SPA after a gateway redirect. Uses the request
     * Origin (the dashboard) so the redirect reaches the user's app even on localhost.
     */
    private function checkoutReturnUrl(Request $request, string $intentToken, bool $cancel = false): string
    {
        $base = rtrim((string) ($request->headers->get('Origin') ?: config('app.url')), '/');
        $params = ['ipaymu_return' => 1, 'intent' => $intentToken];
        if ($cancel) {
            $params['cancel'] = 1;
        }

        return $base . '/dashboard/payments?' . http_build_query($params);
    }

    private function dokuNotifyUrl(): string
    {
        return url($this->providerWebhookPath('doku')) . '?token=' . urlencode((string) $this->dokuSettings()->getConfig()['callback_token']);
    }

    private function gatewayRuntime(): PaymentGatewaySettingsService
    {
        return app(PaymentGatewaySettingsService::class);
    }

    private function xenditSettings(): XenditSettingsService
    {
        return app(XenditSettingsService::class);
    }

    private function xendit(): XenditService
    {
        return app(XenditService::class);
    }

    private function ipaymuSettings(): IpaymuSettingsService
    {
        return app(IpaymuSettingsService::class);
    }

    private function ipaymu(): IpaymuService
    {
        return app(IpaymuService::class);
    }

    private function dokuSettings(): DokuSettingsService
    {
        return app(DokuSettingsService::class);
    }

    private function doku(): DokuService
    {
        return app(DokuService::class);
    }

    private function manualPaymentSettings(): ManualPaymentSettingsService
    {
        return app(ManualPaymentSettingsService::class);
    }

    private function buildCustomerReferenceId(User $user): string
    {
        return 'usr_' . (string) $user->id . '_' . Str::lower(Str::random(10));
    }

    private function walletPayload(OrganizationWallet $wallet): array
    {
        return [
            'id' => (int) $wallet->id,
            'organization_id' => (int) $wallet->organization_id,
            'currency' => (string) $wallet->currency,
            'available_balance' => (int) $wallet->available_balance,
            'pending_balance' => (int) $wallet->pending_balance,
            'total_in' => (int) $wallet->total_in,
            'total_out' => (int) $wallet->total_out,
            'status' => (string) $wallet->status,
            'updated_at' => $wallet->updated_at,
        ];
    }

    private function walletTransactionPayload(OrganizationWalletTransaction $transaction): array
    {
        return [
            'id' => (int) $transaction->id,
            'type' => (string) $transaction->type,
            'direction' => (string) $transaction->direction,
            'amount' => (int) $transaction->amount,
            'balance_after' => (int) $transaction->balance_after,
            'reference_type' => $transaction->reference_type,
            'reference_id' => $transaction->reference_id,
            'external_ref' => $transaction->external_ref,
            'description' => $transaction->description,
            'metadata' => $transaction->metadata,
            'created_at' => $transaction->created_at,
        ];
    }

    private function sendSubscriptionBillingNotification(int $subscriptionId, string $statusLabel): void
    {
        if ($subscriptionId <= 0) {
            return;
        }

        $subscription = Subscription::query()->with(['organization.users', 'app', 'plan'])->find($subscriptionId);
        if (!$subscription instanceof Subscription || !$subscription->organization) {
            return;
        }

        $recipients = $subscription->organization->users
            ->filter(fn ($member) => in_array((string) ($member->pivot->role ?? ''), ['owner', 'admin', 'super_admin'], true))
            ->pluck('email')
            ->filter()
            ->map(fn ($email) => strtolower((string) $email))
            ->unique()
            ->values();

        foreach ($recipients as $email) {
            $this->platformMailService->sendTo($email, new HellomBillingNotificationMail(
                organizationName: (string) $subscription->organization->name,
                appName: (string) ($subscription->app?->name ?? 'Aplikasi'),
                planName: (string) ($subscription->plan?->name ?? 'Plan'),
                statusLabel: $statusLabel,
                amount: (int) $subscription->amount,
                startsAt: $subscription->starts_at,
                endsAt: $subscription->ends_at,
            ));
        }
    }

    private function sendCheckoutStartedNotifications(CheckoutIntent $intent, ?Invoice $invoice, ?string $paymentUrl): void
    {
        $subscription = $intent->subscription?->loadMissing(['organization.users', 'app', 'plan']);
        if (!$subscription instanceof Subscription || !$subscription->organization) {
            return;
        }

        $manualMethods = $this->resolveSelectedManualMethods((string) data_get($intent->metadata, 'manual_payment_method', ''));
        $details = $this->buildCheckoutEmailDetails($intent, $invoice, $paymentUrl);

        foreach ($this->ownerBillingRecipients($subscription) as $email) {
            $this->platformMailService->sendTo($email, new HellomCheckoutStatusMail(
                subjectLine: 'Pembayaran aplikasi baru menunggu tindak lanjut',
                payload: [
                    'headline' => 'Ada checkout aplikasi baru di Hellom',
                    'intro' => 'Seorang pembeli baru saja memulai pembayaran aplikasi. Silakan cek detail di dashboard untuk memantau atau mengonfirmasi pembayaran.',
                    'details' => $details,
                    'closing' => 'Anda bisa membuka akses aplikasi secara manual setelah pembayaran terverifikasi.',
                ]
            ));
        }

        if ($intent->user?->email) {
            $this->platformMailService->sendTo((string) $intent->user->email, new HellomCheckoutStatusMail(
                subjectLine: 'Instruksi pembayaran aplikasi Hellom',
                payload: [
                    'headline' => 'Checkout aplikasi Anda sudah dibuat',
                    'intro' => $paymentUrl
                        ? 'Silakan lanjutkan pembayaran menggunakan link gateway yang sudah disiapkan.'
                        : 'Silakan lakukan pembayaran menggunakan metode manual yang dipilih, lalu tunggu konfirmasi owner.',
                    'details' => $details,
                    'manual_methods' => $manualMethods,
                    'closing' => $paymentUrl ? 'Link pembayaran tersedia di detail di atas.' : 'Setelah owner memverifikasi pembayaran, akses aplikasi akan dibuka.',
                ]
            ));
        }
    }

    private function sendCheckoutDecisionNotifications(CheckoutIntent $intent, bool $approved): void
    {
        $subscription = $intent->subscription?->loadMissing(['organization.users', 'app', 'plan']);
        if (!$subscription instanceof Subscription || !$subscription->organization) {
            return;
        }

        $details = $this->buildCheckoutEmailDetails($intent, null, null);
        $subject = $approved ? 'Pembayaran aplikasi berhasil dikonfirmasi' : 'Pembayaran aplikasi ditolak';
        $headline = $approved ? 'Pembayaran aplikasi sudah dikonfirmasi' : 'Checkout aplikasi belum bisa diproses';
        $intro = $approved
            ? 'Pembayaran manual sudah dikonfirmasi. Akses aplikasi telah dibuka sesuai plan yang dipilih.'
            : 'Owner menolak checkout manual ini. Silakan hubungi owner atau buat pembayaran baru.';

        foreach ($this->ownerBillingRecipients($subscription) as $email) {
            $this->platformMailService->sendTo($email, new HellomCheckoutStatusMail(
                subjectLine: $subject,
                payload: [
                    'headline' => $headline,
                    'intro' => $intro,
                    'details' => $details,
                ]
            ));
        }

        if ($intent->user?->email) {
            $this->platformMailService->sendTo((string) $intent->user->email, new HellomCheckoutStatusMail(
                subjectLine: $subject,
                payload: [
                    'headline' => $headline,
                    'intro' => $intro,
                    'details' => $details,
                ]
            ));
        }
    }

    /**
     * @return array<int,string>
     */
    private function ownerBillingRecipients(Subscription $subscription): array
    {
        return $subscription->organization->users
            ->filter(fn ($member) => in_array((string) ($member->pivot->role ?? ''), ['owner', 'admin', 'super_admin'], true))
            ->pluck('email')
            ->filter()
            ->map(fn ($email) => strtolower((string) $email))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string,string>
     */
    private function buildCheckoutEmailDetails(CheckoutIntent $intent, ?Invoice $invoice, ?string $paymentUrl): array
    {
        return [
            'Organisasi' => (string) ($intent->organization?->name ?? $intent->subscription?->organization?->name ?? '-'),
            'Aplikasi' => (string) ($intent->app?->name ?? '-'),
            'Plan' => (string) ($intent->plan?->name ?? '-'),
            'Billing cycle' => (string) data_get($intent->metadata, 'billing_cycle', $intent->subscription?->billing_cycle ?? '-'),
            'Nominal' => 'Rp ' . number_format((int) $intent->amount, 0, ',', '.'),
            'Status checkout' => (string) $intent->status,
            'Invoice' => (string) ($invoice?->invoice_number ?? ''),
            'Metode manual' => (string) data_get($intent->metadata, 'manual_payment_method', ''),
            'Payment URL' => (string) ($paymentUrl ?? ''),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function resolveSelectedManualMethods(string $selectedMethod): array
    {
        $options = $this->manualPaymentSettings()->publicOptions();
        $methods = collect((array) ($options['methods'] ?? []));

        if ($selectedMethod === '') {
            return $methods->all();
        }

        return $methods
            ->filter(fn (array $method) => (string) ($method['key'] ?? '') === $selectedMethod)
            ->values()
            ->all();
    }
}
