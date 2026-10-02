<?php

namespace App\Http\Controllers\Api\V1\Hellom\Billing;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Http\Controllers\Api\V1\Hellom\Billing\Concerns\ActivatesPlans;
use App\Http\Controllers\Api\V1\Hellom\Billing\Concerns\InteractsWithPaymentGateways;
use App\Models\AppCatalog;
use App\Models\CheckoutIntent;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CheckoutNotifier;
use App\Services\Hellom\SubscriptionCheckoutActivationService;
use App\Services\NotificationService;
use App\Services\Billing\PaymentPolicy;
use App\Services\Payments\IpaymuPaymentVerifier;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Member checkout (gateway/manual), wallet top-up sessions, billing overview/history
 * and gateway reconciliation.
 */
class BillingController extends BaseApiController
{
    use ActivatesPlans, InteractsWithPaymentGateways;

    public function __construct(
        private readonly CheckoutNotifier $checkoutNotifier,
        private readonly NotificationService $notificationService,
    ) {
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

        // The super-admin settings decide (PaymentPolicy): automatic gateway, or manual
        // confirmation — which also serves as backup while the active gateway is not ready.
        if ($paymentFlow === 'direct') {
            $directMode = app(PaymentPolicy::class)->checkoutOptions()['direct_mode'];
            if ($directMode !== 'unavailable') {
                $checkoutMode = $directMode;
            }
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
                        // The intent amount (yearly = price x 10) is what grants the period.
                        'price' => [(int) $intent->amount],
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
                            'callback_url' => FrontendUrl::to('/dashboard/payments'),
                            'callback_url_result' => FrontendUrl::to('/dashboard/payments'),
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
                        'success_return_url' => FrontendUrl::to('/dashboard/payments'),
                        'cancel_return_url' => FrontendUrl::to('/dashboard/payments'),
                        'description' => "Aktivasi {$app->name} - {$plan->name}",
                        'items' => [
                            [
                                'reference_id' => (string) $plan->slug,
                                'type' => 'DIGITAL_SERVICE',
                                'name' => "{$app->name} - {$plan->name}",
                                'net_unit_amount' => (int) $intent->amount,
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
                $this->checkoutNotifier->sendCheckoutStartedNotifications($freshIntent, $invoice, $paymentUrl);
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
                        'callback_url' => FrontendUrl::to('/dashboard/payments'),
                        'callback_url_result' => FrontendUrl::to('/dashboard/payments'),
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
                    'success_return_url' => FrontendUrl::to('/dashboard/payments'),
                    'cancel_return_url' => FrontendUrl::to('/dashboard/payments'),
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
        // An id we stored ourselves is trusted; one the browser brings back is only a hint,
        // so iPaymu must then also confirm that the payment belongs to this checkout.
        $storedTransactionId = trim((string) ($ipaymuMeta['transaction_id'] ?? '')) ?: trim((string) ($ipaymuMeta['payment_session_id'] ?? ''));
        $transactionId = $storedTransactionId ?: trim((string) ($validated['transaction_id'] ?? ''));

        if ($transactionId === '') {
            return $this->ok(['active' => false, 'status' => 'pending'], 'Menunggu pembayaran.');
        }

        try {
            $check = app(IpaymuPaymentVerifier::class)->verify((string) $intent->intent_token, (int) $intent->amount, $transactionId, $storedTransactionId !== '');

            if ($check['ok']) {
                $activator->confirmGatewayCheckout($intent, [
                    'transaction_id' => (string) ($check['status']->transactionId ?: $transactionId),
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
}
