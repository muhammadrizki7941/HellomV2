<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\CheckoutIntent;
use App\Models\Entitlement;
use App\Models\Invoice;
use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;
use App\Models\PaymentEvent;
use App\Models\ProductPurchase;
use App\Models\Subscription;
use App\Mail\HellomCheckoutStatusMail;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Hellom\PlatformMailService;
use App\Services\Hellom\PosProvisioningService;
use App\Services\Hellom\SubscriptionCheckoutActivationService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IpaymuWebhookController extends BaseApiController
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $callbackToken = (string) app(IpaymuSettingsService::class)->getConfig()['callback_token'];
        $requestToken = (string) ($request->query('token') ?: $request->header('X-IPAYMU-TOKEN', ''));

        if ($callbackToken === '' || !hash_equals($callbackToken, $requestToken)) {
            return $this->fail('Invalid iPaymu callback token', ['code' => 'INVALID_IPAYMU_CALLBACK_TOKEN'], 401);
        }

        $payload = $request->all();
        $eventType = strtolower((string) ($payload['status'] ?? $payload['Status'] ?? $payload['transactionStatus'] ?? 'notification'));
        $eventId = (string) ($payload['transaction_id'] ?? $payload['transactionId'] ?? $payload['trx_id'] ?? $payload['sid'] ?? '');
        if ($eventId === '') {
            $eventId = hash('sha256', json_encode([$request->query(), $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $metadata = [
            'purpose' => (string) $request->query('purpose', ''),
            'organization_id' => (int) $request->query('organization_id', 0),
            'user_id' => (int) $request->query('user_id', 0),
            'subscription_id' => (int) $request->query('subscription_id', 0),
            'checkout_intent_id' => (int) $request->query('checkout_intent_id', 0),
            'invoice_id' => (int) $request->query('invoice_id', 0),
            'purchase_id' => (int) $request->query('purchase_id', 0),
            'product_id' => (int) $request->query('product_id', 0),
            'reference_id' => (string) $request->query('reference_id', ''),
            'channel' => (string) $request->query('channel', ''),
        ];

        $organizationId = (int) $metadata['organization_id'];

        $paymentEvent = PaymentEvent::query()->updateOrCreate(
            [
                'provider' => 'ipaymu',
                'event_id' => $eventId,
            ],
            [
                'event_type' => $eventType,
                'organization_id' => $organizationId > 0 ? $organizationId : null,
                'status' => 'received',
                'error_message' => null,
                'payload' => [
                    'query' => $request->query(),
                    'body' => $payload,
                ],
            ]
        );

        if (!$this->isSuccessPayload($payload)) {
            if ($organizationId > 0 && (string) $metadata['purpose'] === 'subscription_checkout') {
                $intent = $this->resolveSubscriptionIntent($metadata);
                if ($intent instanceof CheckoutIntent && $intent->user instanceof \App\Models\User) {
                    $status = $this->normalizePaymentStatus($payload);
                    $productName = (string) ($intent->app?->name ?? 'Aplikasi');
                    if (in_array($status, ['pending', 'processing', 'waiting'], true)) {
                        $this->notificationService->notifyConsumerPaymentPending($intent->user, $intent, $productName);
                    } else {
                        $this->notificationService->notifyConsumerPaymentFailed($intent->user, $intent, $productName);
                    }
                }
            }

            if ((string) $metadata['purpose'] === 'product_purchase') {
                $purchase = $this->resolveProductPurchase($metadata, $payload);
                if ($purchase instanceof ProductPurchase && $purchase->user && $purchase->product) {
                    $status = $this->normalizePaymentStatus($payload);
                    if (in_array($status, ['pending', 'processing', 'waiting', 'unpaid'], true)) {
                        $this->updateProductPurchaseStatus($purchase, 'pending', $payload);
                        $this->notificationService->notifyConsumerPaymentPending($purchase->user, $purchase, $purchase->product->name);
                    } else {
                        $this->updateProductPurchaseStatus($purchase, 'failed', $payload);
                        $this->notificationService->notifyConsumerPaymentFailed($purchase->user, $purchase, $purchase->product->name);
                    }
                }
            }

            $paymentEvent->forceFill([
                'status' => 'ignored',
                'error_message' => 'iPaymu notification was not marked successful.',
            ])->save();

            return $this->ok([
                'event_id' => $eventId,
                'status' => 'ignored',
            ], 'iPaymu webhook ignored');
        }

        try {
            if ($organizationId > 0 && (string) $metadata['purpose'] === 'subscription_checkout') {
                $this->activateSubscriptionCheckout($payload, $metadata);
            }

            if ($organizationId > 0 && (string) $metadata['purpose'] === 'wallet_topup') {
                $this->creditWalletTopup($payload, $metadata, $eventId);
            }

            if ((string) $metadata['purpose'] === 'product_purchase') {
                $purchase = $this->resolveProductPurchase($metadata, $payload);
                if ($purchase instanceof ProductPurchase && $purchase->user && $purchase->product) {
                    $this->updateProductPurchaseStatus($purchase, 'paid', $payload);
                    $this->notificationService->notifyConsumerPaymentSuccess($purchase->user, $purchase, $purchase->product->name);
                    $this->notificationService->notifyConsumerAccessActivated($purchase->user, null, $purchase->product->name);
                }
            }

            $paymentEvent->forceFill([
                'status' => 'processed',
                'error_message' => null,
            ])->save();
        } catch (\Throwable $exception) {
            $paymentEvent->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        return $this->ok([
            'event_id' => $eventId,
            'status' => 'processed',
        ], 'iPaymu webhook processed');
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $metadata
     */
    private function activateSubscriptionCheckout(array $payload, array $metadata): void
    {
        $intentId = (int) ($metadata['checkout_intent_id'] ?? 0);
        $referenceId = (string) ($metadata['reference_id'] ?? '');
        $transactionId = (string) ($payload['transaction_id'] ?? $payload['transactionId'] ?? $payload['trx_id'] ?? '');
        $sessionId = (string) ($payload['sid'] ?? '');

        $intent = CheckoutIntent::query()
            ->with(['subscription', 'app', 'plan', 'user'])
            ->when($intentId > 0, fn ($query) => $query->where('id', $intentId))
            ->when($intentId <= 0 && $referenceId !== '', fn ($query) => $query->where('intent_token', $referenceId))
            ->first();

        if (!$intent instanceof CheckoutIntent) {
            return;
        }

        if (in_array((string) $intent->status, ['confirmed', 'paid'], true)) {
            app(SubscriptionCheckoutActivationService::class)->ensureActiveAccessForConfirmedCheckout($intent);

            return;
        }

        DB::transaction(function () use ($intent, $metadata, $transactionId, $sessionId): void {
            $now = now();
            $intentMeta = is_array($intent->metadata) ? $intent->metadata : [];
            $intentMeta['ipaymu'] = array_filter([
                'transaction_id' => $transactionId,
                'session_id' => $sessionId,
            ]);
            $intentMeta['activated_at'] = $now->toISOString();

            $intent->forceFill([
                'status' => 'confirmed',
                'metadata' => $intentMeta,
            ])->save();

            $subscription = $intent->subscription;
            if ($subscription instanceof Subscription) {
                $subscriptionMeta = is_array($subscription->metadata) ? $subscription->metadata : [];
                $subscriptionMeta['activation_source'] = 'ipaymu_payment';
                $subscriptionMeta['ipaymu'] = array_filter([
                    'transaction_id' => $transactionId,
                    'session_id' => $sessionId,
                ]);

                $subscription->forceFill([
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => $now->copy()->addMonth(),
                    'metadata' => $subscriptionMeta,
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

            $invoiceId = (int) ($metadata['invoice_id'] ?? 0);
            if ($invoiceId > 0) {
                $invoice = Invoice::query()->find($invoiceId);
                if ($invoice instanceof Invoice) {
                    $invoiceMeta = is_array($invoice->metadata) ? $invoice->metadata : [];
                    $invoiceMeta['ipaymu'] = array_filter([
                        'transaction_id' => $transactionId,
                        'session_id' => $sessionId,
                    ]);
                    $invoice->forceFill([
                        'status' => 'paid',
                        'paid_at' => $now,
                        'metadata' => $invoiceMeta,
                    ])->save();
                }
            }

            if ((int) $intent->amount > 0) {
                \App\Models\PlatformFinanceLedger::recordRevenue(
                    'ipaymu_subscription_payment',
                    (int) $intent->amount,
                    (int) $intent->organization_id,
                    'checkout_intents',
                    (int) $intent->id,
                    'Subscription payment confirmed by iPaymu webhook'
                );
            }

            if ((string) ($intent->app?->slug ?? '') === 'pos') {
                app(PosProvisioningService::class)->ensureProvisionedForPos((int) $intent->organization_id);
            }

            $this->sendSuccessNotifications($intent);
        });
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $metadata
     */
    private function creditWalletTopup(array $payload, array $metadata, string $eventId): void
    {
        $organizationId = (int) ($metadata['organization_id'] ?? 0);
        $userId = (int) ($metadata['user_id'] ?? 0);
        $amount = (int) ($payload['amount'] ?? $payload['nominal'] ?? $payload['total'] ?? 0);
        $referenceId = (string) ($metadata['reference_id'] ?? '');
        $transactionId = (string) ($payload['transaction_id'] ?? $payload['transactionId'] ?? $payload['trx_id'] ?? '');

        if ($organizationId <= 0 || $amount <= 0) {
            return;
        }

        DB::transaction(function () use ($organizationId, $userId, $amount, $referenceId, $transactionId, $eventId, $metadata): void {
            $existingCredit = OrganizationWalletTransaction::query()
                ->where('organization_id', $organizationId)
                ->where('type', 'payment_credit')
                ->where(function ($query) use ($eventId, $referenceId, $transactionId): void {
                    $query->where('metadata->event_id', $eventId);

                    if ($referenceId !== '') {
                        $query->orWhere('external_ref', $referenceId);
                    }

                    if ($transactionId !== '') {
                        $query->orWhere('metadata->transaction_id', $transactionId);
                    }
                })
                ->exists();

            if ($existingCredit) {
                return;
            }

            if ($userId > 0) {
                \App\Models\UserWalletLedger::recordDeposit(
                    $userId,
                    $amount,
                    'ipaymu_webhook',
                    null,
                    'User wallet top-up via iPaymu webhook'
                );
            }

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

            $wallet->forceFill([
                'available_balance' => (int) $wallet->available_balance + $amount,
                'total_in' => (int) $wallet->total_in + $amount,
            ])->save();

            OrganizationWalletTransaction::query()->create([
                'organization_id' => $organizationId,
                'wallet_id' => (int) $wallet->id,
                'user_id' => null,
                'type' => 'payment_credit',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => (int) $wallet->available_balance,
                'reference_type' => 'ipaymu_event',
                'reference_id' => 'success',
                'external_ref' => $referenceId !== '' ? $referenceId : $transactionId,
                'description' => 'Incoming payment credited from iPaymu webhook',
                'metadata' => [
                    'event_id' => $eventId,
                    'transaction_id' => $transactionId,
                    'channel' => (string) ($metadata['channel'] ?? 'redirect'),
                    'settlement_mode' => 'assumed_instant',
                    'settlement_status' => 'settled',
                ],
            ]);
        });
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $payload
     */
    private function resolveProductPurchase(array $metadata, array $payload): ?ProductPurchase
    {
        $purchaseId = (int) ($metadata['purchase_id'] ?? 0);
        $referenceId = (string) ($metadata['reference_id'] ?? '');
        $transactionRef = (string) (
            $payload['reference_id']
            ?? $payload['referenceId']
            ?? $referenceId
        );

        return ProductPurchase::query()
            ->with(['user', 'product'])
            ->when($purchaseId > 0, fn ($query) => $query->where('id', $purchaseId))
            ->when($purchaseId <= 0 && $transactionRef !== '', fn ($query) => $query->where('transaction_code', $transactionRef))
            ->first();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function updateProductPurchaseStatus(ProductPurchase $purchase, string $status, array $payload): void
    {
        if ($status === 'paid' && $purchase->payment_status === 'paid') {
            return;
        }

        $purchase->forceFill([
            'payment_status' => $status,
            'payment_gateway' => 'ipaymu',
            'gateway_ref' => (string) (
                $payload['transaction_id']
                ?? $payload['transactionId']
                ?? $payload['trx_id']
                ?? $purchase->gateway_ref
                ?? ''
            ),
            'paid_at' => $status === 'paid' ? now() : $purchase->paid_at,
        ])->save();

        if ($status === 'paid') {
            $purchase->product?->increment('total_purchases');
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function isSuccessPayload(array $payload): bool
    {
        $status = strtolower(trim((string) (
            $payload['status']
            ?? $payload['Status']
            ?? $payload['transactionStatus']
            ?? $payload['payment_status']
            ?? ''
        )));

        if (in_array($status, ['berhasil', 'success', 'successful', 'completed', 'paid', 'settlement', 'settled'], true)) {
            return true;
        }

        $success = $payload['success'] ?? $payload['Success'] ?? null;
        if ($success !== null) {
            return filter_var($success, FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    private function sendSuccessNotifications(CheckoutIntent $intent): void
    {
        $this->notificationService->createGatewayPaymentSuccessNotif($intent, 'iPaymu');
        if ($intent->user instanceof \App\Models\User) {
            $productName = (string) ($intent->app?->name ?? 'Aplikasi');
            $this->notificationService->notifyConsumerPaymentSuccess($intent->user, $intent, $productName);
            if ($intent->subscription instanceof Subscription) {
                $this->notificationService->notifyConsumerAccessActivated($intent->user, $intent->subscription, $productName);
            }
        }

        $subscription = $intent->subscription?->loadMissing(['organization.users']);
        if (!$subscription instanceof Subscription || !$subscription->organization) {
            return;
        }

        $details = [
            'Organisasi' => (string) $subscription->organization->name,
            'Aplikasi' => (string) ($intent->app?->name ?? '-'),
            'Plan' => (string) ($intent->plan?->name ?? '-'),
            'Nominal' => 'Rp ' . number_format((int) $intent->amount, 0, ',', '.'),
            'Status' => 'paid',
            'Provider' => 'iPaymu',
        ];

        $mailer = app(PlatformMailService::class);
        $recipients = $subscription->organization->users
            ->filter(fn ($member) => in_array((string) ($member->pivot->role ?? ''), ['owner', 'admin', 'super_admin'], true))
            ->pluck('email')
            ->filter()
            ->unique()
            ->values();

        foreach ($recipients as $email) {
            $mailer->sendTo((string) $email, new HellomCheckoutStatusMail(
                subjectLine: 'Pembayaran aplikasi berhasil via iPaymu',
                payload: [
                    'headline' => 'Pembayaran gateway berhasil diterima',
                    'intro' => 'Checkout aplikasi telah dibayar melalui iPaymu dan akses aplikasi sudah diaktifkan.',
                    'details' => $details,
                ]
            ));
        }

        if ($intent->user?->email) {
            $mailer->sendTo((string) $intent->user->email, new HellomCheckoutStatusMail(
                subjectLine: 'Pembayaran aplikasi Anda berhasil',
                payload: [
                    'headline' => 'Pembayaran berhasil',
                    'intro' => 'Pembayaran aplikasi Anda via iPaymu berhasil diproses.',
                    'details' => $details,
                ]
            ));
        }
    }

    private function resolveSubscriptionIntent(array $metadata): ?CheckoutIntent
    {
        $intentId = (int) ($metadata['checkout_intent_id'] ?? 0);
        $referenceId = (string) ($metadata['reference_id'] ?? '');

        return CheckoutIntent::query()
            ->with(['subscription', 'app', 'plan', 'user'])
            ->when($intentId > 0, fn ($query) => $query->where('id', $intentId))
            ->when($intentId <= 0 && $referenceId !== '', fn ($query) => $query->where('intent_token', $referenceId))
            ->first();
    }

    private function normalizePaymentStatus(array $payload): string
    {
        return strtolower(trim((string) (
            $payload['status']
            ?? $payload['Status']
            ?? $payload['transactionStatus']
            ?? $payload['payment_status']
            ?? ''
        )));
    }
}
