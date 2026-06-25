<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\CheckoutIntent;
use App\Models\Entitlement;
use App\Models\Invoice;
use App\Models\PaymentEvent;
use App\Models\ProductPurchase;
use App\Models\Subscription;
use App\Mail\HellomCheckoutStatusMail;
use App\Services\Hellom\DokuSettingsService;
use App\Services\Hellom\LandingSaleService;
use App\Services\Hellom\PlatformMailService;
use App\Services\Hellom\PosProvisioningService;
use App\Services\Hellom\SubscriptionCheckoutActivationService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        try {
            $invoiceNumber = (string) ($order['invoice_number'] ?? '');

            // Landing-page product sale (invoice number == order reference "lps_...")
            if (str_starts_with($invoiceNumber, 'lps_')) {
                if (in_array($status, ['SUCCESS', 'PAID'], true)) {
                    app(LandingSaleService::class)->settlePaidOrderByReference($invoiceNumber, [
                        'provider' => 'doku',
                        'gateway_ref' => (string) ($payment['token_id'] ?? $invoiceNumber),
                    ]);
                } elseif (in_array($status, ['EXPIRED', 'FAILED', 'CANCELLED'], true)) {
                    app(LandingSaleService::class)->markFailedByReference($invoiceNumber);
                }

                $paymentEvent->forceFill([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'error_message' => null,
                ])->save();

                return $this->ok(['status' => 'processed', 'provider' => 'doku'], 'DOKU webhook processed');
            }

            $orderAdditional = is_array($order['additional_info'] ?? null) ? $order['additional_info'] : [];
            $productPurchase = $this->resolveProductPurchase($invoiceNumber, $orderAdditional);

            if ($productPurchase instanceof ProductPurchase) {
                $this->handleProductPurchaseStatus($productPurchase, $status, $payment, $order);

                $paymentEvent->forceFill([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'error_message' => null,
                ])->save();

                return $this->ok([
                    'status' => 'processed',
                    'provider' => 'doku',
                ], 'DOKU webhook processed');
            }
            $invoice = $invoiceNumber !== ''
                ? Invoice::query()->where('invoice_number', $invoiceNumber)->first()
                : null;
            $intentToken = (string) data_get($invoice?->metadata, 'intent_token', '');

            $intent = $intentToken !== ''
                ? CheckoutIntent::query()->with(['subscription', 'app', 'plan'])->where('intent_token', $intentToken)->first()
                : null;

            if (!$intent instanceof CheckoutIntent) {
                $paymentEvent->forceFill([
                    'status' => 'ignored',
                    'error_message' => 'Checkout intent not found for DOKU webhook.',
                ])->save();

                return $this->ok([
                    'status' => 'ignored',
                    'provider' => 'doku',
                ], 'DOKU webhook ignored');
            }

            if (in_array($status, ['SUCCESS', 'PAID'], true)) {
                $this->activateCheckout($intent, $invoice, $payment, $order);
                $this->sendSuccessNotifications($intent->fresh(['subscription.organization.users', 'app', 'plan', 'user']));
            } elseif (in_array($status, ['PENDING', 'WAITING_PAYMENT', 'UNPAID'], true)) {
                $this->sendPendingNotifications($intent->fresh(['subscription', 'app', 'plan', 'user']));
            } elseif (in_array($status, ['EXPIRED', 'FAILED', 'CANCELLED'], true)) {
                $this->markCheckoutAsFailed($intent, $invoice, strtolower($status), $payment, $order);
                $this->sendFailedNotifications($intent->fresh(['subscription', 'app', 'plan', 'user']));
            }

            $paymentEvent->forceFill([
                'status' => 'processed',
                'processed_at' => now(),
                'error_message' => null,
            ])->save();

            return $this->ok([
                'status' => 'processed',
                'provider' => 'doku',
            ], 'DOKU webhook processed');
        } catch (\Throwable $exception) {
            $paymentEvent->forceFill([
                'status' => 'failed',
                'processed_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $additionalInfo
     */
    private function resolveProductPurchase(string $invoiceNumber, array $additionalInfo): ?ProductPurchase
    {
        $purchaseId = (int) ($additionalInfo['purchase_id'] ?? 0);
        $purpose = (string) ($additionalInfo['purpose'] ?? '');

        if ($purchaseId <= 0 && $invoiceNumber === '' && $purpose !== 'product_purchase') {
            return null;
        }

        return ProductPurchase::query()
            ->with(['user', 'product'])
            ->when($purchaseId > 0, fn ($query) => $query->where('id', $purchaseId))
            ->when($purchaseId <= 0 && $invoiceNumber !== '', fn ($query) => $query->where('transaction_code', $invoiceNumber))
            ->first();
    }

    /**
     * @param array<string,mixed> $payment
     * @param array<string,mixed> $order
     */
    private function handleProductPurchaseStatus(ProductPurchase $purchase, string $status, array $payment, array $order): void
    {
        $normalized = strtoupper($status);
        $paymentStatus = null;

        if (in_array($normalized, ['SUCCESS', 'PAID'], true)) {
            $paymentStatus = 'paid';
        } elseif (in_array($normalized, ['PENDING', 'WAITING_PAYMENT', 'UNPAID'], true)) {
            $paymentStatus = 'pending';
        } elseif (in_array($normalized, ['EXPIRED', 'FAILED', 'CANCELLED'], true)) {
            $paymentStatus = 'failed';
        }

        if ($paymentStatus === null) {
            return;
        }

        if ($paymentStatus === 'paid' && $purchase->payment_status === 'paid') {
            return;
        }

        $purchase->forceFill([
            'payment_status' => $paymentStatus,
            'payment_gateway' => 'doku',
            'gateway_ref' => (string) ($payment['invoice_number'] ?? $order['invoice_number'] ?? $payment['token_id'] ?? $purchase->gateway_ref ?? ''),
            'paid_at' => $paymentStatus === 'paid' ? now() : $purchase->paid_at,
        ])->save();

        if ($paymentStatus === 'paid') {
            $purchase->product?->increment('total_purchases');
        }

        if ($purchase->user && $purchase->product) {
            if ($paymentStatus === 'paid') {
                $this->notificationService->notifyConsumerPaymentSuccess($purchase->user, $purchase, $purchase->product->name);
                $this->notificationService->notifyConsumerAccessActivated($purchase->user, null, $purchase->product->name);
            } elseif ($paymentStatus === 'pending') {
                $this->notificationService->notifyConsumerPaymentPending($purchase->user, $purchase, $purchase->product->name);
            } elseif ($paymentStatus === 'failed') {
                $this->notificationService->notifyConsumerPaymentFailed($purchase->user, $purchase, $purchase->product->name);
            }
        }
    }

    /**
     * @param array<string,mixed> $payment
     * @param array<string,mixed> $order
     */
    private function activateCheckout(CheckoutIntent $intent, ?Invoice $invoice, array $payment, array $order): void
    {
        if (in_array((string) $intent->status, ['confirmed', 'paid'], true)) {
            app(SubscriptionCheckoutActivationService::class)->ensureActiveAccessForConfirmedCheckout($intent);

            return;
        }

        DB::transaction(function () use ($intent, $invoice, $payment, $order): void {
            $now = now();
            $intentMeta = is_array($intent->metadata) ? $intent->metadata : [];
            $intentMeta['doku'] = array_filter([
                'invoice_number' => (string) ($order['invoice_number'] ?? ''),
                'payment_url' => (string) ($payment['url'] ?? ''),
                'payment_method_types' => $payment['payment_method_types'] ?? [],
                'paid_at' => $now->toISOString(),
            ]);

            $intent->forceFill([
                'status' => 'confirmed',
                'metadata' => $intentMeta,
            ])->save();

            $subscription = $intent->subscription;
            if ($subscription instanceof Subscription) {
                $subscriptionMeta = is_array($subscription->metadata) ? $subscription->metadata : [];
                $subscriptionMeta['activation_source'] = 'doku_payment';
                $subscriptionMeta['doku'] = $intentMeta['doku'];

                $subscription->forceFill([
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => $this->resolveSubscriptionEndAt($subscription->plan, $now),
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

            if ($invoice instanceof Invoice) {
                $invoiceMeta = is_array($invoice->metadata) ? $invoice->metadata : [];
                $invoiceMeta['doku'] = $intentMeta['doku'];
                $invoice->forceFill([
                    'status' => 'paid',
                    'paid_at' => $now,
                    'metadata' => $invoiceMeta,
                ])->save();
            }

            if ((int) $intent->amount > 0) {
                \App\Models\PlatformFinanceLedger::recordRevenue(
                    'doku_subscription_payment',
                    (int) $intent->amount,
                    (int) $intent->organization_id,
                    'checkout_intents',
                    (int) $intent->id,
                    'Subscription payment confirmed by DOKU webhook'
                );
            }

            if ((string) ($intent->app?->slug ?? '') === 'pos') {
                app(PosProvisioningService::class)->ensureProvisionedForPos((int) $intent->organization_id);
            }
        });
    }

    /**
     * @param array<string,mixed> $payment
     * @param array<string,mixed> $order
     */
    private function markCheckoutAsFailed(CheckoutIntent $intent, ?Invoice $invoice, string $status, array $payment, array $order): void
    {
        DB::transaction(function () use ($intent, $invoice, $status, $payment, $order): void {
            $intentMeta = is_array($intent->metadata) ? $intent->metadata : [];
            $intentMeta['doku'] = array_filter([
                'invoice_number' => (string) ($order['invoice_number'] ?? ''),
                'payment_url' => (string) ($payment['url'] ?? ''),
                'status' => $status,
                'updated_at' => now()->toISOString(),
            ]);

            $intent->forceFill([
                'status' => $status === 'expired' ? 'expired' : 'failed',
                'metadata' => $intentMeta,
            ])->save();

            if ($intent->subscription instanceof Subscription) {
                $intent->subscription->forceFill([
                    'status' => $status === 'expired' ? 'expired' : 'failed',
                ])->save();
            }

            if ($invoice instanceof Invoice) {
                $invoiceMeta = is_array($invoice->metadata) ? $invoice->metadata : [];
                $invoiceMeta['doku'] = $intentMeta['doku'];
                $invoice->forceFill([
                    'status' => $status,
                    'metadata' => $invoiceMeta,
                ])->save();
            }
        });
    }

    private function resolveSubscriptionEndAt(?\App\Models\Plan $plan, \Illuminate\Support\Carbon $startAt): ?\Illuminate\Support\Carbon
    {
        if (!$plan instanceof \App\Models\Plan) {
            return $startAt->copy()->addMonth();
        }

        if ($plan->isLifetime()) {
            return null;
        }

        if ($plan->duration_days) {
            return $startAt->copy()->addDays((int) $plan->duration_days);
        }

        if ($plan->hasBillingCycle(\App\Models\Plan::BILLING_YEARLY)) {
            return $startAt->copy()->addYear();
        }

        return $startAt->copy()->addMonth();
    }

    private function sendSuccessNotifications(?CheckoutIntent $intent): void
    {
        if (!$intent instanceof CheckoutIntent) {
            return;
        }

        $this->notificationService->createGatewayPaymentSuccessNotif($intent, 'DOKU');
        if ($intent->user instanceof \App\Models\User) {
            $productName = (string) ($intent->app?->name ?? 'Aplikasi');
            $this->notificationService->notifyConsumerPaymentSuccess($intent->user, $intent, $productName);
            if ($intent->subscription instanceof Subscription) {
                $this->notificationService->notifyConsumerAccessActivated($intent->user, $intent->subscription, $productName);
            }
        }

        $subscription = $intent->subscription;
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

    private function sendPendingNotifications(?CheckoutIntent $intent): void
    {
        if (!$intent instanceof CheckoutIntent || !$intent->user instanceof \App\Models\User) {
            return;
        }

        $this->notificationService->notifyConsumerPaymentPending(
            $intent->user,
            $intent,
            (string) ($intent->app?->name ?? 'Aplikasi')
        );
    }

    private function sendFailedNotifications(?CheckoutIntent $intent): void
    {
        if (!$intent instanceof CheckoutIntent || !$intent->user instanceof \App\Models\User) {
            return;
        }

        $this->notificationService->notifyConsumerPaymentFailed(
            $intent->user,
            $intent,
            (string) ($intent->app?->name ?? 'Aplikasi')
        );
    }
}
