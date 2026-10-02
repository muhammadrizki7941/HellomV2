<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\CheckoutIntent;
use App\Models\Invoice;
use App\Models\PaymentEvent;
use App\Models\ProductPurchase;
use App\Models\Subscription;
use App\Mail\HellomCheckoutStatusMail;
use App\Services\Hellom\DokuSettingsService;
use App\Services\Payments\Gateways\DokuGateway;
use App\Services\Payments\PaymentStatus;
use App\Services\SellerFinance\LandingPaymentService;
use App\Services\Hellom\PlatformMailService;
use App\Services\Hellom\SubscriptionCheckoutActivationService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DOKU Checkout notifications. Every notification must carry DOKU's HMAC signature;
 * the payment state is then taken from DOKU's order status API (status, reference,
 * amount), never from the notification body. Paid records are never downgraded.
 */
class DokuWebhookController extends BaseApiController
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $callbackToken = (string) app(DokuSettingsService::class)->getConfig()['callback_token'];
        $requestToken = (string) ($request->query('token') ?: $request->header('X-DOKU-TOKEN', ''));

        if ($callbackToken === '' || !hash_equals($callbackToken, $requestToken)) {
            return $this->fail('Invalid DOKU callback token', ['code' => 'INVALID_DOKU_CALLBACK_TOKEN'], 401);
        }

        $payload = $request->all();
        $order = (array) ($payload['order'] ?? []);
        $payment = (array) ($payload['payment'] ?? []);
        $status = strtoupper((string) ($order['status'] ?? $payment['status'] ?? 'PENDING'));
        $eventId = (string) ($payment['invoice_number'] ?? $order['invoice_number'] ?? $payment['token_id'] ?? '');
        $invoiceNumber = (string) ($order['invoice_number'] ?? '');

        $paymentEvent = PaymentEvent::query()->updateOrCreate(
            [
                'provider' => 'doku',
                'event_id' => $eventId !== '' ? $eventId : hash('sha256', json_encode($payload)),
            ],
            [
                'event_type' => strtolower($status),
                'status' => 'received',
                'payload' => $payload,
            ]
        );

        if (!app(DokuGateway::class)->verifyWebhook($request)) {
            $request->attributes->set('webhook_log', ['event_id' => $eventId, 'reference' => $invoiceNumber, 'signature_valid' => false, 'outcome' => 'rejected', 'error' => 'invalid signature']);
            $paymentEvent->forceFill(['status' => 'failed', 'error_message' => 'Invalid DOKU signature'])->save();

            return $this->fail('Invalid DOKU signature', ['code' => 'INVALID_DOKU_SIGNATURE'], 401);
        }

        try {
            if (str_starts_with($invoiceNumber, 'lps_')) {
                // Landing-page product sale (invoice number == order reference).
                $outcome = app(LandingPaymentService::class)->handleNotification('doku', $invoiceNumber);
            } else {
                $outcome = $this->handleBillingNotification($invoiceNumber, $order);
            }
        } catch (\Throwable $exception) {
            report($exception);
            $paymentEvent->forceFill(['status' => 'failed', 'processed_at' => now(), 'error_message' => $exception->getMessage()])->save();
            $request->attributes->set('webhook_log', ['event_id' => $eventId, 'reference' => $invoiceNumber, 'signature_valid' => true, 'outcome' => 'error', 'error' => $exception->getMessage()]);

            // 5xx so DOKU retries.
            return $this->fail('Verifikasi pembayaran sementara gagal', ['code' => 'GATEWAY_CHECK_FAILED'], 503);
        }

        $request->attributes->set('webhook_log', ['event_id' => $eventId, 'reference' => $invoiceNumber, 'signature_valid' => true, 'outcome' => $outcome]);
        $paymentEvent->forceFill([
            'status' => in_array($outcome, ['processed', 'duplicate', 'pending', 'failed', 'expired'], true) ? 'processed' : 'ignored',
            'processed_at' => now(),
            'error_message' => $outcome === 'processed' ? null : $outcome,
        ])->save();

        return $this->ok(['status' => $outcome, 'provider' => 'doku'], 'DOKU webhook processed');
    }

    /** @param array<string,mixed> $order */
    private function handleBillingNotification(string $invoiceNumber, array $order): string
    {
        if ($invoiceNumber === '') {
            return 'unknown_reference';
        }

        $additionalInfo = is_array($order['additional_info'] ?? null) ? $order['additional_info'] : [];
        $purchase = $this->resolveProductPurchase($invoiceNumber, $additionalInfo);
        $invoice = $purchase ? null : Invoice::query()->where('invoice_number', $invoiceNumber)->first();
        $intentToken = (string) data_get($invoice?->metadata, 'intent_token', '');
        $intent = $intentToken !== ''
            ? CheckoutIntent::query()->with(['subscription', 'app', 'plan', 'user'])->where('intent_token', $intentToken)->first()
            : null;

        if (!$purchase instanceof ProductPurchase && !$intent instanceof CheckoutIntent) {
            return 'unknown_reference';
        }

        $status = app(DokuGateway::class)->getStatus($invoiceNumber, null, null);
        if ($status->reference !== null && $status->reference !== $invoiceNumber) {
            return 'reference_mismatch';
        }

        return $purchase instanceof ProductPurchase
            ? $this->applyToPurchase($purchase, $status, $invoiceNumber)
            : $this->applyToCheckout($intent, $invoice, $status, $invoiceNumber);
    }

    private function applyToPurchase(ProductPurchase $purchase, PaymentStatus $status, string $invoiceNumber): string
    {
        if ($status->state === PaymentStatus::PAID) {
            if ($status->amount !== (int) $purchase->amount_paid) {
                return $status->amount === null ? 'amount_unknown' : 'amount_mismatch';
            }

            $paidNow = DB::transaction(function () use ($purchase, $invoiceNumber): bool {
                $locked = ProductPurchase::query()->lockForUpdate()->find((int) $purchase->id);
                if (!$locked instanceof ProductPurchase || $locked->payment_status === 'paid') {
                    return false;
                }
                $locked->forceFill(['payment_status' => 'paid', 'payment_gateway' => 'doku', 'gateway_ref' => $invoiceNumber, 'paid_at' => now()])->save();
                $locked->product?->increment('total_purchases');

                return true;
            }, 3);

            if ($paidNow && $purchase->user && $purchase->product) {
                $this->notificationService->notifyConsumerPaymentSuccess($purchase->user, $purchase, $purchase->product->name);
                $this->notificationService->notifyConsumerAccessActivated($purchase->user, null, $purchase->product->name);
            }

            return $paidNow ? 'processed' : 'duplicate';
        }

        $newStatus = match ($status->state) {
            PaymentStatus::PENDING => 'pending',
            PaymentStatus::FAILED, PaymentStatus::EXPIRED => 'failed',
            default => null,
        };
        if ($newStatus === null) {
            return $status->state;
        }

        // A paid purchase is never downgraded by a late or out-of-order notification.
        $changed = ProductPurchase::query()->whereKey($purchase->id)->whereNotIn('payment_status', ['paid', $newStatus])
            ->update(['payment_status' => $newStatus, 'payment_gateway' => 'doku', 'updated_at' => now()]);
        if ($changed && $purchase->user && $purchase->product) {
            $newStatus === 'pending'
                ? $this->notificationService->notifyConsumerPaymentPending($purchase->user, $purchase, $purchase->product->name)
                : $this->notificationService->notifyConsumerPaymentFailed($purchase->user, $purchase, $purchase->product->name);
        }

        return $status->state;
    }

    private function applyToCheckout(CheckoutIntent $intent, ?Invoice $invoice, PaymentStatus $status, string $invoiceNumber): string
    {
        if ($status->state === PaymentStatus::PAID) {
            if ($status->amount !== (int) $intent->amount) {
                return $status->amount === null ? 'amount_unknown' : 'amount_mismatch';
            }

            $newlyConfirmed = app(SubscriptionCheckoutActivationService::class)->confirmGatewayCheckout($intent, [
                'invoice_number' => $invoiceNumber,
                'invoice_id' => (int) ($invoice?->id ?? 0),
            ], 'DOKU');
            if ($newlyConfirmed) {
                $this->sendSuccessEmails($intent->fresh(['subscription.organization.users', 'app', 'plan', 'user']));
            }

            return $newlyConfirmed ? 'processed' : 'duplicate';
        }

        if ($status->state === PaymentStatus::PENDING) {
            $this->notifyConsumer($intent, 'pending');

            return 'pending';
        }

        if (in_array($status->state, [PaymentStatus::FAILED, PaymentStatus::EXPIRED], true) && $this->markCheckoutAsFailed($intent, $invoice, $status->state)) {
            $this->notifyConsumer($intent, 'failed');
        }

        return $status->state;
    }

    /** Only an unpaid checkout can fail or expire; true when this call changed it. */
    private function markCheckoutAsFailed(CheckoutIntent $intent, ?Invoice $invoice, string $state): bool
    {
        $status = $state === PaymentStatus::EXPIRED ? 'expired' : 'failed';

        return DB::transaction(function () use ($intent, $invoice, $status): bool {
            $locked = CheckoutIntent::query()->with('subscription')->lockForUpdate()->find((int) $intent->id);
            if (!$locked instanceof CheckoutIntent || in_array((string) $locked->status, ['confirmed', 'paid', 'failed', 'expired', 'rejected'], true)) {
                return false;
            }

            $meta = is_array($locked->metadata) ? $locked->metadata : [];
            $meta['doku'] = array_merge($meta['doku'] ?? [], ['status' => $status, 'updated_at' => now()->toISOString()]);
            $locked->forceFill(['status' => $status, 'metadata' => $meta])->save();

            if ($locked->subscription instanceof Subscription && in_array((string) $locked->subscription->status, ['pending_payment', 'draft'], true)) {
                $locked->subscription->forceFill(['status' => $status])->save();
            }
            if ($invoice instanceof Invoice && (string) $invoice->status !== 'paid') {
                $invoice->forceFill(['status' => $status])->save();
            }

            return true;
        }, 3);
    }

    /** @param array<string,mixed> $additionalInfo */
    private function resolveProductPurchase(string $invoiceNumber, array $additionalInfo): ?ProductPurchase
    {
        $purchaseId = (int) ($additionalInfo['purchase_id'] ?? 0);

        return ProductPurchase::query()
            ->with(['user', 'product'])
            ->when($purchaseId > 0, fn ($query) => $query->where('id', $purchaseId)->where('transaction_code', $invoiceNumber))
            ->when($purchaseId <= 0, fn ($query) => $query->where('transaction_code', $invoiceNumber))
            ->first();
    }

    private function notifyConsumer(CheckoutIntent $intent, string $state): void
    {
        if (!$intent->user instanceof \App\Models\User) {
            return;
        }
        $productName = (string) ($intent->app?->name ?? 'Aplikasi');
        $state === 'pending'
            ? $this->notificationService->notifyConsumerPaymentPending($intent->user, $intent, $productName)
            : $this->notificationService->notifyConsumerPaymentFailed($intent->user, $intent, $productName);
    }

    /** Emails; the in-app notifications come from SubscriptionCheckoutActivationService. */
    private function sendSuccessEmails(?CheckoutIntent $intent): void
    {
        $subscription = $intent?->subscription;
        if (!$subscription instanceof Subscription || !$subscription->organization) {
            return;
        }

        $details = [
            'Organisasi' => (string) $subscription->organization->name,
            'Aplikasi' => (string) ($intent->app?->name ?? '-'),
            'Plan' => (string) ($intent->plan?->name ?? '-'),
            'Nominal' => 'Rp ' . number_format((int) $intent->amount, 0, ',', '.'),
            'Status' => 'paid',
            'Provider' => 'DOKU',
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
                subjectLine: 'Pembayaran aplikasi berhasil via DOKU',
                payload: [
                    'headline' => 'Pembayaran gateway berhasil diterima',
                    'intro' => 'Checkout aplikasi telah dibayar melalui DOKU dan akses aplikasi sudah diaktifkan.',
                    'details' => $details,
                ]
            ));
        }

        if ($intent->user?->email) {
            $mailer->sendTo((string) $intent->user->email, new HellomCheckoutStatusMail(
                subjectLine: 'Pembayaran aplikasi Anda berhasil',
                payload: [
                    'headline' => 'Pembayaran berhasil',
                    'intro' => 'Pembayaran aplikasi Anda via DOKU berhasil diproses.',
                    'details' => $details,
                ]
            ));
        }
    }
}
