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
use App\Models\WalletWithdrawalRequest;
use App\Mail\HellomCheckoutStatusMail;
use App\Services\Hellom\LandingSaleService;
use App\Services\Hellom\PlatformMailService;
use App\Services\Hellom\PosProvisioningService;
use App\Services\Hellom\SubscriptionCheckoutActivationService;
use App\Services\Hellom\XenditSettingsService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class XenditWebhookController extends BaseApiController
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $callbackToken = (string) app(XenditSettingsService::class)->getConfig()['callback_token'];
        $headerToken = (string) $request->header('X-CALLBACK-TOKEN', '');

        if ($callbackToken === '' || !hash_equals($callbackToken, $headerToken)) {
            return $this->fail('Invalid Xendit callback token', ['code' => 'INVALID_XENDIT_CALLBACK_TOKEN'], 401);
        }

        $payload = $request->all();

        $eventType = (string) ($payload['event'] ?? $payload['type'] ?? 'unknown');
        $eventId = (string) ($payload['id'] ?? $payload['event_id'] ?? '');
        if ($eventId === '') {
            $eventId = hash('sha256', json_encode($payload));
        }

        $metadata = $this->resolveMetadata($payload);
        $organizationId = (int) (
            $payload['organization_id']
            ?? data_get($metadata, 'organization_id', 0)
        );

        $existing = PaymentEvent::query()
            ->where('provider', 'xendit')
            ->where('event_id', $eventId)
            ->first();

        if ($existing instanceof PaymentEvent) {
            $existing->forceFill([
                'organization_id' => $existing->organization_id ?? ($organizationId > 0 ? $organizationId : null),
                'status' => 'received',
                'error_message' => null,
                'payload' => $payload,
            ])->save();
            $paymentEvent = $existing;
        } else {
            $paymentEvent = PaymentEvent::query()->create([
                'provider' => 'xendit',
                'event_id' => $eventId,
                'event_type' => $eventType,
                'organization_id' => $organizationId > 0 ? $organizationId : null,
                'status' => 'received',
                'payload' => $payload,
            ]);
        }

        try {
            $purpose = (string) ($metadata['purpose'] ?? '');

            if ($organizationId > 0 && $purpose === 'subscription_checkout') {
                if ($this->isIncomingPaymentEvent($eventType)) {
                    $this->activateSubscriptionCheckout($payload, $metadata);
                } elseif ($this->isSubscriptionPendingEvent($eventType)) {
                    $this->sendPendingNotifications($payload, $metadata);
                } elseif ($this->isSubscriptionFailedEvent($eventType)) {
                    $this->sendFailedNotifications($payload, $metadata);
                }
            }

            if ($purpose === 'product_purchase') {
                $this->handleProductPurchaseEvent($payload, $metadata, $eventType);
            }

            if ($purpose === 'landing_sale') {
                $reference = (string) (
                    $metadata['reference_id']
                    ?? $payload['reference_id']
                    ?? data_get($payload, 'data.reference_id')
                    ?? data_get($payload, 'data.external_id')
                    ?? ''
                );
                if ($this->isIncomingPaymentEvent($eventType)) {
                    app(LandingSaleService::class)->settlePaidOrderByReference($reference, [
                        'provider' => 'xendit',
                        'gateway_ref' => (string) ($payload['payment_id'] ?? data_get($payload, 'data.payment_id') ?? data_get($payload, 'id') ?? ''),
                    ]);
                } elseif ($this->isSubscriptionFailedEvent($eventType)) {
                    app(LandingSaleService::class)->markFailedByReference($reference);
                }
            }

            if ($organizationId > 0 && $this->isIncomingPaymentEvent($eventType) && !in_array($purpose, ['subscription_checkout', 'landing_sale'], true)) {
                $amount = (int) ($payload['amount'] ?? data_get($payload, 'data.amount', 0));
                $externalRef = (string) (
                    $payload['external_id']
                    ?? data_get($payload, 'data.external_id')
                    ?? $payload['reference_id']
                    ?? data_get($payload, 'data.reference_id')
                    ?? ''
                );

                if ($amount > 0) {
                    $channel = $this->resolvePaymentChannel($payload);
                    $policy = $this->resolveChannelPolicy($channel);
                    $settledNow = $this->isSettledPayment($payload, $eventType, $policy);

                    DB::transaction(function () use ($organizationId, $amount, $externalRef, $eventType, $eventId, $policy, $settledNow, $channel, $purpose): void {
                        $existingCredit = OrganizationWalletTransaction::query()
                            ->where('organization_id', $organizationId)
                            ->whereIn('type', ['payment_credit', 'payment_credit_pending'])
                            ->where(function ($query) use ($eventId, $externalRef): void {
                                $query->where('metadata->event_id', $eventId);

                                if ($externalRef !== '') {
                                    $query->orWhere('external_ref', $externalRef);
                                }
                            })
                            ->exists();

                        if ($existingCredit) {
                            return;
                        }

                        // Handle user wallet top-ups vs organization revenue
                        if ($purpose === 'wallet_topup') {
                            // This is a user deposit - record in user wallet ledger
                            $userId = (int) ($metadata['user_id'] ?? 0);
                            if ($userId > 0) {
                                \App\Models\UserWalletLedger::recordDeposit(
                                    $userId,
                                    $amount,
                                    'xendit_webhook',
                                    null,
                                    'User wallet top-up via Xendit webhook'
                                );
                            }
                        } else {
                            // This is organization revenue - record in platform finance ledger
                            \App\Models\PlatformFinanceLedger::recordRevenue(
                                'subscription_payment',
                                $amount,
                                $organizationId,
                                'xendit_webhook',
                                null,
                                'Organization subscription payment via Xendit webhook'
                            );
                        }

                        // Still maintain organization wallet for backwards compatibility
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

                        if ($settledNow) {
                            $wallet->forceFill([
                                'available_balance' => (int) $wallet->available_balance + $amount,
                                'total_in' => (int) $wallet->total_in + $amount,
                            ])->save();
                        } else {
                            $wallet->forceFill([
                                'pending_balance' => (int) $wallet->pending_balance + $amount,
                                'total_in' => (int) $wallet->total_in + $amount,
                            ])->save();
                        }

                        $settlementHours = max(0, (int) ($policy['settlement_hours'] ?? 24));
                        $settlementEta = now()->copy()->addHours($settlementHours);

                        OrganizationWalletTransaction::query()->create([
                            'organization_id' => $organizationId,
                            'wallet_id' => (int) $wallet->id,
                            'user_id' => null,
                            'type' => $settledNow ? 'payment_credit' : 'payment_credit_pending',
                            'direction' => 'credit',
                            'amount' => $amount,
                            'balance_after' => (int) $wallet->available_balance,
                            'reference_type' => 'xendit_event',
                            'reference_id' => $eventType,
                            'external_ref' => $externalRef,
                            'description' => $settledNow
                                ? 'Incoming payment settled and credited from Xendit webhook'
                                : 'Incoming payment credited as pending settlement',
                            'metadata' => [
                                'event_id' => $eventId,
                                'event_type' => $eventType,
                                'channel' => $channel,
                                'purpose' => $purpose,
                                'settlement_mode' => (string) ($policy['settlement_mode'] ?? 'pending'),
                                'settlement_status' => $settledNow ? 'settled' : 'pending',
                                'settlement_eta' => $settledNow ? now() : $settlementEta,
                                'fee_fixed' => (int) ($policy['fee_fixed'] ?? 0),
                                'fee_bps' => (int) ($policy['fee_bps'] ?? 0),
                                'bank_cutoff' => (string) ($policy['bank_cutoff'] ?? '17:00'),
                            ],
                        ]);
                    });
                }
            }

            if ($organizationId > 0 && $this->isSettlementEvent($eventType)) {
                $externalRef = (string) (
                    $payload['external_id']
                    ?? data_get($payload, 'data.external_id')
                    ?? $payload['reference_id']
                    ?? data_get($payload, 'data.reference_id')
                    ?? ''
                );
                $amount = (int) ($payload['amount'] ?? data_get($payload, 'data.amount', 0));

                if ($externalRef !== '') {
                    DB::transaction(function () use ($organizationId, $externalRef, $amount, $eventType): void {
                        $wallet = OrganizationWallet::query()
                            ->where('organization_id', $organizationId)
                            ->lockForUpdate()
                            ->first();

                        if (!$wallet instanceof OrganizationWallet) {
                            return;
                        }

                        $alreadyReleased = OrganizationWalletTransaction::query()
                            ->where('organization_id', $organizationId)
                            ->where('type', 'payment_settle_release')
                            ->where('external_ref', $externalRef)
                            ->exists();

                        if ($alreadyReleased) {
                            return;
                        }

                        $pendingCredit = OrganizationWalletTransaction::query()
                            ->where('organization_id', $organizationId)
                            ->where('type', 'payment_credit_pending')
                            ->where('external_ref', $externalRef)
                            ->latest('id')
                            ->first();

                        $releaseAmount = $amount > 0
                            ? $amount
                            : (int) ($pendingCredit?->amount ?? 0);

                        if ($releaseAmount <= 0) {
                            return;
                        }

                        $wallet->forceFill([
                            'pending_balance' => max(0, (int) $wallet->pending_balance - $releaseAmount),
                            'available_balance' => (int) $wallet->available_balance + $releaseAmount,
                        ])->save();

                        OrganizationWalletTransaction::query()->create([
                            'organization_id' => $organizationId,
                            'wallet_id' => (int) $wallet->id,
                            'user_id' => null,
                            'type' => 'payment_settle_release',
                            'direction' => 'credit',
                            'amount' => $releaseAmount,
                            'balance_after' => (int) $wallet->available_balance,
                            'reference_type' => 'xendit_settlement_event',
                            'reference_id' => $eventType,
                            'external_ref' => $externalRef,
                            'description' => 'Payment settlement released to available balance',
                            'metadata' => [
                                'event_type' => $eventType,
                            ],
                        ]);
                    });
                }
            }

            if ($this->isWithdrawalPaidEvent($eventType)) {
                $externalRef = (string) ($payload['external_id'] ?? data_get($payload, 'data.external_id', ''));
                $providerRef = (string) ($payload['id'] ?? data_get($payload, 'data.id', ''));

                if ($externalRef !== '') {
                    $withdrawal = WalletWithdrawalRequest::query()->where('external_ref', $externalRef)->first();
                    if ($withdrawal instanceof WalletWithdrawalRequest && in_array((string) $withdrawal->status, ['pending', 'processing'], true)) {
                        DB::transaction(function () use ($withdrawal, $providerRef): void {
                            $wallet = OrganizationWallet::query()
                                ->where('id', (int) $withdrawal->wallet_id)
                                ->lockForUpdate()
                                ->first();

                            if ($wallet instanceof OrganizationWallet) {
                                $amount = (int) $withdrawal->amount;
                                $wallet->forceFill([
                                    'pending_balance' => max(0, (int) $wallet->pending_balance - $amount),
                                    'total_out' => (int) $wallet->total_out + $amount,
                                ])->save();

                                OrganizationWalletTransaction::query()->create([
                                    'organization_id' => (int) $withdrawal->organization_id,
                                    'wallet_id' => (int) $wallet->id,
                                    'user_id' => null,
                                    'type' => 'withdrawal_paid',
                                    'direction' => 'debit',
                                    'amount' => $amount,
                                    'balance_after' => (int) $wallet->available_balance,
                                    'reference_type' => 'wallet_withdrawal_requests',
                                    'reference_id' => (string) $withdrawal->id,
                                    'external_ref' => (string) $withdrawal->external_ref,
                                    'description' => 'Withdrawal paid by provider',
                                ]);
                            }

                            $withdrawal->forceFill([
                                'status' => 'paid',
                                'provider_ref' => $providerRef !== '' ? $providerRef : $withdrawal->provider_ref,
                                'processed_at' => now(),
                            ])->save();
                        });
                    }
                }
            }

            if ($this->isWithdrawalFailedEvent($eventType)) {
                $externalRef = (string) ($payload['external_id'] ?? data_get($payload, 'data.external_id', ''));

                if ($externalRef !== '') {
                    $withdrawal = WalletWithdrawalRequest::query()->where('external_ref', $externalRef)->first();
                    if ($withdrawal instanceof WalletWithdrawalRequest && in_array((string) $withdrawal->status, ['pending', 'processing'], true)) {
                        DB::transaction(function () use ($withdrawal): void {
                            $wallet = OrganizationWallet::query()
                                ->where('id', (int) $withdrawal->wallet_id)
                                ->lockForUpdate()
                                ->first();

                            if ($wallet instanceof OrganizationWallet) {
                                $amount = (int) $withdrawal->amount;
                                $wallet->forceFill([
                                    'pending_balance' => max(0, (int) $wallet->pending_balance - $amount),
                                    'available_balance' => (int) $wallet->available_balance + $amount,
                                ])->save();

                                OrganizationWalletTransaction::query()->create([
                                    'organization_id' => (int) $withdrawal->organization_id,
                                    'wallet_id' => (int) $wallet->id,
                                    'user_id' => null,
                                    'type' => 'withdrawal_failed_release',
                                    'direction' => 'credit',
                                    'amount' => $amount,
                                    'balance_after' => (int) $wallet->available_balance,
                                    'reference_type' => 'wallet_withdrawal_requests',
                                    'reference_id' => (string) $withdrawal->id,
                                    'external_ref' => (string) $withdrawal->external_ref,
                                    'description' => 'Withdrawal failed and balance returned to available wallet',
                                ]);
                            }

                            $withdrawal->forceFill([
                                'status' => 'failed',
                                'processed_at' => now(),
                            ])->save();
                        });
                    }
                }
            }

            $paymentEvent->forceFill([
                'status' => 'processed',
                'processed_at' => now(),
            ])->save();

            return $this->ok([
                'provider' => 'xendit',
                'event_id' => $eventId,
                'event_type' => $eventType,
                'status' => 'processed',
            ], 'Xendit webhook processed');
        } catch (\Throwable $exception) {
            $paymentEvent->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'processed_at' => now(),
            ])->save();

            return $this->fail('Xendit webhook processing failed', [
                'code' => 'XENDIT_WEBHOOK_PROCESSING_FAILED',
                'event_id' => $eventId,
            ], 500);
        }
    }

    private function isIncomingPaymentEvent(string $eventType): bool
    {
        $supported = [
            'invoice.paid',
            'payment.succeeded',
            'payment.capture',
            'ewallet.capture.succeeded',
            'payment_session.completed',
        ];

        return in_array(strtolower($eventType), $supported, true);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $metadata
     */
    private function handleProductPurchaseEvent(array $payload, array $metadata, string $eventType): void
    {
        $purchaseId = (int) ($metadata['purchase_id'] ?? 0);
        $reference = (string) (
            $payload['reference_id']
            ?? data_get($payload, 'data.reference_id')
            ?? $payload['external_id']
            ?? data_get($payload, 'data.external_id')
            ?? ''
        );

        $purchase = ProductPurchase::query()
            ->with(['user', 'product'])
            ->when($purchaseId > 0, fn ($query) => $query->where('id', $purchaseId))
            ->when($purchaseId <= 0 && $reference !== '', fn ($query) => $query->where('transaction_code', $reference))
            ->first();

        if (!$purchase instanceof ProductPurchase) {
            return;
        }

        if ($purchase->payment_status === 'paid') {
            return;
        }

        $status = null;
        if ($this->isIncomingPaymentEvent($eventType)) {
            $status = 'paid';
        } elseif ($this->isSubscriptionPendingEvent($eventType)) {
            $status = 'pending';
        } elseif ($this->isSubscriptionFailedEvent($eventType)) {
            $status = 'failed';
        }

        if ($status === null) {
            return;
        }

        DB::transaction(function () use ($purchase, $status, $payload): void {
            $purchase->forceFill([
                'payment_status' => $status,
                'payment_gateway' => 'xendit',
                'gateway_ref' => (string) (
                    $payload['payment_id']
                    ?? data_get($payload, 'data.payment_id')
                    ?? data_get($payload, 'id')
                    ?? $purchase->gateway_ref
                    ?? ''
                ),
                'paid_at' => $status === 'paid' ? now() : $purchase->paid_at,
            ])->save();

            if ($status === 'paid') {
                $purchase->product?->increment('total_purchases');
            }
        });

        if ($purchase->user && $purchase->product) {
            if ($status === 'paid') {
                $this->notificationService->notifyConsumerPaymentSuccess($purchase->user, $purchase, $purchase->product->name);
                $this->notificationService->notifyConsumerAccessActivated($purchase->user, null, $purchase->product->name);
            } elseif ($status === 'pending') {
                $this->notificationService->notifyConsumerPaymentPending($purchase->user, $purchase, $purchase->product->name);
            } elseif ($status === 'failed') {
                $this->notificationService->notifyConsumerPaymentFailed($purchase->user, $purchase, $purchase->product->name);
            }
        }
    }

    private function isSubscriptionPendingEvent(string $eventType): bool
    {
        return in_array(strtolower($eventType), [
            'invoice.pending',
            'payment.pending',
            'payment.awaiting_capture',
            'payment_session.pending',
        ], true);
    }

    private function isSubscriptionFailedEvent(string $eventType): bool
    {
        return in_array(strtolower($eventType), [
            'invoice.expired',
            'invoice.failed',
            'payment.failed',
            'payment.expired',
            'payment.cancelled',
            'payment_session.failed',
            'payment_session.cancelled',
        ], true);
    }

    private function isWithdrawalPaidEvent(string $eventType): bool
    {
        $supported = [
            'disbursement.succeeded',
            'payout.paid',
        ];

        return in_array(strtolower($eventType), $supported, true);
    }

    private function isWithdrawalFailedEvent(string $eventType): bool
    {
        $supported = [
            'disbursement.failed',
            'payout.failed',
        ];

        return in_array(strtolower($eventType), $supported, true);
    }

    private function isSettlementEvent(string $eventType): bool
    {
        $supported = [
            'invoice.settled',
            'payment.settled',
            'payment.available',
            'settlement.completed',
        ];

        return in_array(strtolower($eventType), $supported, true);
    }

    private function resolvePaymentChannel(array $payload): string
    {
        $candidate = strtolower(trim((string) (
            $payload['channel']
            ?? $payload['payment_channel']
            ?? $payload['payment_method']
            ?? $payload['channel_code']
            ?? data_get($payload, 'data.channel')
            ?? data_get($payload, 'data.payment_channel')
            ?? data_get($payload, 'data.payment_method')
            ?? data_get($payload, 'metadata.channel')
            ?? data_get($payload, 'data.metadata.channel')
            ?? 'default'
        )));

        if ($candidate === '') {
            return 'default';
        }

        if (str_contains($candidate, 'qris')) {
            return 'qris';
        }

        if (str_contains($candidate, 'va') || str_contains($candidate, 'virtual')) {
            return 'va';
        }

        if (str_contains($candidate, 'ewallet') || in_array($candidate, ['ovo', 'dana', 'linkaja', 'shopeepay', 'gopay'], true)) {
            return 'ewallet';
        }

        if (str_contains($candidate, 'card') || str_contains($candidate, 'credit')) {
            return 'card';
        }

        return $candidate;
    }

    private function resolveChannelPolicy(string $channel): array
    {
        $defaultPolicy = (array) config('payments.providers.xendit.policy.default', []);
        $channels = (array) config('payments.providers.xendit.policy.channels', []);
        $selected = (array) ($channels[$channel] ?? []);

        return array_merge($defaultPolicy, $selected, [
            'channel' => $channel,
        ]);
    }

    private function isSettledPayment(array $payload, string $eventType, array $policy): bool
    {
        if ($this->isSettlementEvent($eventType)) {
            return true;
        }

        if (strtolower($eventType) === 'payment_session.completed') {
            return true;
        }

        $settlementMode = strtolower((string) ($policy['settlement_mode'] ?? 'pending'));
        if ($settlementMode === 'instant') {
            return true;
        }

        $settlementStatus = strtolower((string) (
            $payload['settlement_status']
            ?? data_get($payload, 'data.settlement_status')
            ?? data_get($payload, 'metadata.settlement_status')
            ?? ''
        ));

        if (in_array($settlementStatus, ['settled', 'completed', 'available'], true)) {
            return true;
        }

        $paymentStatus = strtoupper((string) (
            $payload['status']
            ?? data_get($payload, 'data.status')
            ?? ''
        ));

        if ($paymentStatus === 'COMPLETED') {
            return true;
        }

        $isSettled = $payload['is_settled']
            ?? data_get($payload, 'data.is_settled')
            ?? data_get($payload, 'metadata.is_settled')
            ?? false;

        return filter_var($isSettled, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function resolveMetadata(array $payload): array
    {
        $metadata = $payload['metadata']
            ?? data_get($payload, 'data.metadata')
            ?? data_get($payload, 'data.items.0.metadata')
            ?? [];

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $metadata
     */
    private function activateSubscriptionCheckout(array $payload, array $metadata): void
    {
        $intentId = (int) ($metadata['checkout_intent_id'] ?? 0);
        $referenceId = (string) ($payload['reference_id'] ?? data_get($payload, 'data.reference_id', ''));
        $sessionId = (string) ($payload['payment_session_id'] ?? data_get($payload, 'data.payment_session_id', ''));
        $paymentId = (string) ($payload['payment_id'] ?? data_get($payload, 'data.payment_id', ''));

        $intent = CheckoutIntent::query()
            ->with(['subscription', 'app', 'plan', 'user'])
            ->when($intentId > 0, fn($query) => $query->where('id', $intentId))
            ->when($intentId <= 0 && $referenceId !== '', fn($query) => $query->where('intent_token', $referenceId))
            ->first();

        if (!$intent instanceof CheckoutIntent) {
            return;
        }

        if (in_array((string) $intent->status, ['confirmed', 'paid'], true)) {
            app(SubscriptionCheckoutActivationService::class)->ensureActiveAccessForConfirmedCheckout($intent);

            return;
        }

        DB::transaction(function () use ($intent, $metadata, $sessionId, $paymentId): void {
            $now = now();
            $intentMeta = is_array($intent->metadata) ? $intent->metadata : [];
            $intentMeta['xendit'] = array_filter([
                'payment_session_id' => $sessionId,
                'payment_id' => $paymentId,
            ]);
            $intentMeta['activated_at'] = $now->toISOString();

            $intent->forceFill([
                'status' => 'confirmed',
                'metadata' => $intentMeta,
            ])->save();

            $subscription = $intent->subscription;
            if ($subscription instanceof Subscription) {
                $subscriptionMeta = is_array($subscription->metadata) ? $subscription->metadata : [];
                $subscriptionMeta['activation_source'] = 'xendit_payment';
                $subscriptionMeta['xendit'] = array_filter([
                    'payment_session_id' => $sessionId,
                    'payment_id' => $paymentId,
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
                    $invoiceMeta['xendit'] = array_filter([
                        'payment_session_id' => $sessionId,
                        'payment_id' => $paymentId,
                    ]);
                    $invoice->forceFill([
                        'status' => 'paid',
                        'paid_at' => $now,
                        'metadata' => $invoiceMeta,
                    ])->save();
                }
            }

            if ((string) ($intent->app?->slug ?? '') === 'pos') {
                app(PosProvisioningService::class)->ensureProvisionedForPos((int) $intent->organization_id);
            }

            $this->sendSuccessNotifications($intent);
        });
    }

    private function sendSuccessNotifications(CheckoutIntent $intent): void
    {
        $this->notificationService->createGatewayPaymentSuccessNotif($intent, 'Xendit');
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
            'Provider' => 'Xendit',
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
                subjectLine: 'Pembayaran aplikasi berhasil via Xendit',
                payload: [
                    'headline' => 'Pembayaran gateway berhasil diterima',
                    'intro' => 'Checkout aplikasi telah dibayar melalui Xendit dan akses aplikasi sudah diaktifkan.',
                    'details' => $details,
                ]
            ));
        }

        if ($intent->user?->email) {
            $mailer->sendTo((string) $intent->user->email, new HellomCheckoutStatusMail(
                subjectLine: 'Pembayaran aplikasi Anda berhasil',
                payload: [
                    'headline' => 'Pembayaran berhasil',
                    'intro' => 'Pembayaran aplikasi Anda via Xendit berhasil diproses.',
                    'details' => $details,
                ]
            ));
        }
    }

    private function sendPendingNotifications(array $payload, array $metadata): void
    {
        $intent = $this->resolveSubscriptionIntent($payload, $metadata);
        if (!$intent instanceof CheckoutIntent || !$intent->user instanceof \App\Models\User) {
            return;
        }

        $this->notificationService->notifyConsumerPaymentPending(
            $intent->user,
            $intent,
            (string) ($intent->app?->name ?? 'Aplikasi')
        );
    }

    private function sendFailedNotifications(array $payload, array $metadata): void
    {
        $intent = $this->resolveSubscriptionIntent($payload, $metadata);
        if (!$intent instanceof CheckoutIntent || !$intent->user instanceof \App\Models\User) {
            return;
        }

        $this->notificationService->notifyConsumerPaymentFailed(
            $intent->user,
            $intent,
            (string) ($intent->app?->name ?? 'Aplikasi')
        );
    }

    private function resolveSubscriptionIntent(array $payload, array $metadata): ?CheckoutIntent
    {
        $intentId = (int) ($metadata['checkout_intent_id'] ?? 0);
        $referenceId = (string) ($payload['reference_id'] ?? data_get($payload, 'data.reference_id', ''));

        return CheckoutIntent::query()
            ->with(['subscription', 'app', 'plan', 'user'])
            ->when($intentId > 0, fn ($query) => $query->where('id', $intentId))
            ->when($intentId <= 0 && $referenceId !== '', fn ($query) => $query->where('intent_token', $referenceId))
            ->first();
    }
}
