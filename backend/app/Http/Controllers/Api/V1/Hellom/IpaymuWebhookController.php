<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\CheckoutIntent;
use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;
use App\Models\PaymentEvent;
use App\Models\ProductPurchase;
use App\Models\Subscription;
use App\Mail\HellomCheckoutStatusMail;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\SellerFinance\LandingPaymentService;
use App\Services\Hellom\PlatformMailService;
use App\Services\Hellom\SubscriptionCheckoutActivationService;
use App\Services\Payments\IpaymuPaymentVerifier;
use App\Services\Payments\PaymentStatus;
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

        // Landing-page sale: the notification only triggers a check with iPaymu's own
        // transaction API (status, amount, reference); its body is never trusted.
        if ((string) $metadata['purpose'] === 'landing_sale') {
            $reference = (string) ($metadata['reference_id'] ?? '');
            $transactionId = (string) ($payload['trx_id'] ?? $payload['transaction_id'] ?? $payload['transactionId'] ?? '');
            $request->attributes->set('webhook_log', ['event_id' => $eventId, 'reference' => $reference, 'signature_valid' => true]);

            try {
                $outcome = app(LandingPaymentService::class)->handleNotification('ipaymu', $reference, $transactionId !== '' ? $transactionId : null);
            } catch (\Throwable $exception) {
                report($exception);
                $paymentEvent->forceFill(['status' => 'failed', 'error_message' => $exception->getMessage()])->save();
                $request->attributes->set('webhook_log', ['event_id' => $eventId, 'reference' => $reference, 'signature_valid' => true, 'outcome' => 'error', 'error' => $exception->getMessage()]);

                // 5xx so iPaymu retries; the reconcile job also picks the order up.
                return $this->fail('Verifikasi pembayaran sementara gagal', ['code' => 'GATEWAY_CHECK_FAILED'], 503);
            }

            $paymentEvent->forceFill(['status' => $outcome === 'processed' ? 'processed' : 'ignored', 'error_message' => $outcome])->save();
            $request->attributes->set('webhook_log', ['event_id' => $eventId, 'reference' => $reference, 'signature_valid' => true, 'outcome' => $outcome]);

            return $this->ok(['event_id' => $eventId, 'status' => $outcome], 'iPaymu webhook processed');
        }

        // Subscription checkout, digital product, wallet top-up: the notification is only a
        // trigger. Status, reference and amount come from iPaymu's transaction API.
        $purpose = (string) $metadata['purpose'];
        $transactionId = (string) ($payload['trx_id'] ?? $payload['transaction_id'] ?? $payload['transactionId'] ?? '');
        $signature = IpaymuPaymentVerifier::signatureValid($request->query());

        if ($signature === false) {
            $paymentEvent->forceFill(['status' => 'failed', 'error_message' => 'invalid notify URL signature'])->save();

            return $this->fail('Invalid iPaymu notification signature', ['code' => 'INVALID_IPAYMU_SIGNATURE'], 401);
        }

        try {
            $outcome = match ($purpose) {
                'subscription_checkout' => $this->processSubscriptionCheckout($metadata, $transactionId),
                'product_purchase' => $this->processProductPurchase($metadata, $transactionId),
                // A top-up has no stored amount to compare with: only signed URLs are credited.
                'wallet_topup' => $signature === true ? $this->processWalletTopup($metadata, $transactionId, $eventId) : 'unsigned',
                default => 'unknown_purpose',
            };
        } catch (\Throwable $exception) {
            report($exception);
            $paymentEvent->forceFill(['status' => 'failed', 'error_message' => $exception->getMessage()])->save();

            // 5xx so iPaymu retries; the return URL / reconcile can also confirm the payment.
            return $this->fail('Verifikasi pembayaran sementara gagal', ['code' => 'GATEWAY_CHECK_FAILED'], 503);
        }

        $paymentEvent->forceFill([
            'status' => in_array($outcome, ['processed', 'duplicate'], true) ? 'processed' : 'ignored',
            'error_message' => $outcome === 'processed' ? null : $outcome,
        ])->save();

        return $this->ok([
            'event_id' => $eventId,
            'status' => $outcome,
        ], 'iPaymu webhook processed');
    }

    /** @param array<string,mixed> $metadata */
    private function processSubscriptionCheckout(array $metadata, string $transactionId): string
    {
        $intent = $this->resolveSubscriptionIntent($metadata);
        if (!$intent instanceof CheckoutIntent) {
            return 'unknown_reference';
        }

        $activator = app(SubscriptionCheckoutActivationService::class);
        if (in_array((string) $intent->status, ['confirmed', 'paid'], true)) {
            $activator->ensureActiveAccessForConfirmedCheckout($intent);

            return 'duplicate';
        }

        $check = app(IpaymuPaymentVerifier::class)->verify((string) $intent->intent_token, (int) $intent->amount, $transactionId, true);
        if (!$check['ok']) {
            $this->notifyUnpaidCheckout($intent, $check['reason']);

            return $check['reason'];
        }

        $newlyConfirmed = $activator->confirmGatewayCheckout($intent, [
            'transaction_id' => (string) ($check['status']->transactionId ?: $transactionId),
            'invoice_id' => (int) ($metadata['invoice_id'] ?? 0),
        ], 'iPaymu');

        if ($newlyConfirmed) {
            $this->sendCheckoutEmails($intent->loadMissing(['subscription.organization.users', 'app', 'plan', 'user']));
        }

        return $newlyConfirmed ? 'processed' : 'duplicate';
    }

    private function notifyUnpaidCheckout(CheckoutIntent $intent, string $state): void
    {
        if (!$intent->user instanceof \App\Models\User) {
            return;
        }
        $productName = (string) ($intent->app?->name ?? 'Aplikasi');
        if ($state === PaymentStatus::PENDING) {
            $this->notificationService->notifyConsumerPaymentPending($intent->user, $intent, $productName);
        } elseif (in_array($state, [PaymentStatus::FAILED, PaymentStatus::EXPIRED], true)) {
            $this->notificationService->notifyConsumerPaymentFailed($intent->user, $intent, $productName);
        }
    }

    /** @param array<string,mixed> $metadata */
    private function processProductPurchase(array $metadata, string $transactionId): string
    {
        $purchase = $this->resolveProductPurchase($metadata);
        if (!$purchase instanceof ProductPurchase) {
            return 'unknown_reference';
        }
        if ($purchase->payment_status === 'paid') {
            return 'duplicate';
        }

        $check = app(IpaymuPaymentVerifier::class)->verify((string) $purchase->transaction_code, (int) $purchase->amount_paid, $transactionId, true);
        if (!$check['ok']) {
            $this->recordUnpaidPurchase($purchase, $check['reason']);

            return $check['reason'];
        }

        $paidNow = DB::transaction(function () use ($purchase, $check, $transactionId): bool {
            $locked = ProductPurchase::query()->lockForUpdate()->find((int) $purchase->id);
            if (!$locked instanceof ProductPurchase || $locked->payment_status === 'paid') {
                return false;
            }
            $locked->forceFill([
                'payment_status' => 'paid',
                'payment_gateway' => 'ipaymu',
                'gateway_ref' => (string) ($check['status']->transactionId ?: $transactionId),
                'paid_at' => now(),
            ])->save();
            $locked->product?->increment('total_purchases');

            return true;
        }, 3);

        if ($paidNow && $purchase->user && $purchase->product) {
            $this->notificationService->notifyConsumerPaymentSuccess($purchase->user, $purchase, $purchase->product->name);
            $this->notificationService->notifyConsumerAccessActivated($purchase->user, null, $purchase->product->name);
        }

        return $paidNow ? 'processed' : 'duplicate';
    }

    /** Pending/failed/expired as reported by iPaymu; a paid purchase is never downgraded. */
    private function recordUnpaidPurchase(ProductPurchase $purchase, string $state): void
    {
        $status = match ($state) {
            PaymentStatus::PENDING => 'pending',
            PaymentStatus::FAILED, PaymentStatus::EXPIRED => 'failed',
            default => null,
        };
        if ($status === null || $purchase->payment_status === $status) {
            return;
        }

        $changed = ProductPurchase::query()->whereKey($purchase->id)->where('payment_status', '!=', 'paid')
            ->update(['payment_status' => $status, 'payment_gateway' => 'ipaymu', 'updated_at' => now()]);
        if (!$changed || !$purchase->user || !$purchase->product) {
            return;
        }

        if ($status === 'pending') {
            $this->notificationService->notifyConsumerPaymentPending($purchase->user, $purchase, $purchase->product->name);
        } else {
            $this->notificationService->notifyConsumerPaymentFailed($purchase->user, $purchase, $purchase->product->name);
        }
    }

    /** @param array<string,mixed> $metadata */
    private function processWalletTopup(array $metadata, string $transactionId, string $eventId): string
    {
        $organizationId = (int) ($metadata['organization_id'] ?? 0);
        $referenceId = (string) ($metadata['reference_id'] ?? '');
        if ($organizationId <= 0 || $referenceId === '') {
            return 'unknown_reference';
        }

        // No stored amount for a top-up: iPaymu must confirm our reference, and the
        // amount it reports is what gets credited.
        $check = app(IpaymuPaymentVerifier::class)->verify($referenceId, null, $transactionId, false);
        if (!$check['ok']) {
            return $check['reason'];
        }
        $amount = (int) $check['status']->amount;
        if ($amount <= 0) {
            return 'amount_unknown';
        }

        $credited = $this->creditWalletTopup($metadata, $amount, (string) ($check['status']->transactionId ?: $transactionId), $eventId);

        return $credited ? 'processed' : 'duplicate';
    }

    /** @param array<string,mixed> $metadata */
    private function creditWalletTopup(array $metadata, int $amount, string $transactionId, string $eventId): bool
    {
        $organizationId = (int) ($metadata['organization_id'] ?? 0);
        $userId = (int) ($metadata['user_id'] ?? 0);
        $referenceId = (string) ($metadata['reference_id'] ?? '');

        return DB::transaction(function () use ($organizationId, $userId, $amount, $referenceId, $transactionId, $eventId, $metadata): bool {
            OrganizationWallet::query()->firstOrCreate(
                ['organization_id' => $organizationId],
                ['currency' => 'IDR', 'available_balance' => 0, 'pending_balance' => 0, 'total_in' => 0, 'total_out' => 0, 'status' => 'active']
            );
            // Lock the wallet first so two notifications for one payment cannot both credit.
            $wallet = OrganizationWallet::query()->where('organization_id', $organizationId)->lockForUpdate()->firstOrFail();

            $existingCredit = OrganizationWalletTransaction::query()
                ->where('organization_id', $organizationId)
                ->where('type', 'payment_credit')
                ->where(function ($query) use ($eventId, $referenceId, $transactionId): void {
                    $query->where('metadata->event_id', $eventId)
                        ->orWhere('external_ref', $referenceId)
                        ->orWhere('metadata->transaction_id', $transactionId);
                })
                ->exists();

            if ($existingCredit) {
                return false;
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
                'external_ref' => $referenceId,
                'description' => 'Incoming payment credited from iPaymu webhook',
                'metadata' => [
                    'event_id' => $eventId,
                    'transaction_id' => $transactionId,
                    'channel' => (string) ($metadata['channel'] ?? 'redirect'),
                    'settlement_mode' => 'assumed_instant',
                    'settlement_status' => 'settled',
                    'verified_with_gateway' => true,
                ],
            ]);

            return true;
        }, 3);
    }

    /** Only the (signed) notify URL decides which purchase this is, never the request body. */
    private function resolveProductPurchase(array $metadata): ?ProductPurchase
    {
        $purchaseId = (int) ($metadata['purchase_id'] ?? 0);
        $referenceId = (string) ($metadata['reference_id'] ?? '');

        return ProductPurchase::query()
            ->with(['user', 'product'])
            ->when($purchaseId > 0, fn ($query) => $query->where('id', $purchaseId))
            ->when($purchaseId <= 0 && $referenceId !== '', fn ($query) => $query->where('transaction_code', $referenceId))
            ->when($purchaseId <= 0 && $referenceId === '', fn ($query) => $query->whereRaw('1 = 0'))
            ->first();
    }

    private function sendCheckoutEmails(CheckoutIntent $intent): void
    {
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
            ->when($intentId <= 0 && $referenceId === '', fn ($query) => $query->whereRaw('1 = 0'))
            ->first();
    }
}
