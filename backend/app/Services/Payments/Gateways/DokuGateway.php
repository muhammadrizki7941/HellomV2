<?php

namespace App\Services\Payments\Gateways;

use App\Services\Hellom\DokuService;
use App\Services\Hellom\DokuSettingsService;
use App\Services\Payments\ChargeRequest;
use App\Services\Payments\ChargeResult;
use App\Services\Payments\DisbursementRequest;
use App\Services\Payments\DisbursementResult;
use App\Services\Payments\GatewayBalance;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentStatus;
use Illuminate\Http\Request;
use RuntimeException;

/** DOKU Checkout. Notifications are verified with DOKU's HMAC signature, not only the URL token. */
final class DokuGateway implements PaymentGateway
{
    public function __construct(
        private readonly DokuService $api,
        private readonly DokuSettingsService $settings,
    ) {
    }

    public function name(): string
    {
        return 'doku';
    }

    public function isReady(): bool
    {
        return $this->settings->isReady();
    }

    public function paymentOptions(): array
    {
        return ['other'];
    }

    public function createCharge(ChargeRequest $request): ChargeResult
    {
        $session = $this->api->createCheckout([
            'order' => [
                'amount' => $request->amount,
                'invoice_number' => $request->reference,
                'currency' => 'IDR',
                'callback_url' => $request->returnUrl,
                'callback_url_result' => $request->returnUrl,
                'language' => 'ID',
                'auto_redirect' => true,
                'line_items' => [[
                    'name' => $request->productName,
                    'price' => $request->amount,
                    'quantity' => 1,
                ]],
            ],
            'payment' => [
                'payment_due_date' => 1440,
                'payment_method_types' => $this->settings->getConfig()['payment_method_types'],
            ],
            'customer' => [
                'name' => $request->buyerName,
                'email' => $request->buyerEmail,
            ],
            'additional_info' => [
                'override_notification_url' => url('/api/v1/hellom/webhooks/doku') . '?token=' . urlencode((string) $this->settings->getConfig()['callback_token']),
            ],
        ]);

        return new ChargeResult(
            provider: 'doku',
            mode: 'redirect',
            paymentUrl: (string) (data_get($session, 'response.payment.url') ?: '') ?: null,
            gatewayRef: (string) (data_get($session, 'response.order.session_id') ?: ''),
            raw: $session,
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        $signature = (string) $request->header('Signature', '');
        if ($signature === '') {
            return false;
        }
        $args = [
            (string) $request->header('Client-Id', ''),
            (string) $request->header('Request-Id', ''),
            (string) $request->header('Request-Timestamp', ''),
        ];
        $body = (string) $request->getContent();

        // DOKU signs the notification path; accept it with or without our query string.
        return $this->api->verifyNotificationSignature(...[...$args, $request->getPathInfo(), $body, $signature])
            || $this->api->verifyNotificationSignature(...[...$args, $request->getRequestUri(), $body, $signature]);
    }

    public function getStatus(string $reference, ?string $gatewayRef, ?string $transactionId): PaymentStatus
    {
        $data = $this->api->getOrderStatus($reference);
        $status = strtoupper((string) (data_get($data, 'transaction.status') ?: data_get($data, 'order.status') ?: ''));
        $state = match ($status) {
            'SUCCESS', 'PAID' => PaymentStatus::PAID,
            'EXPIRED' => PaymentStatus::EXPIRED,
            'FAILED', 'CANCELLED', 'CANCELED' => PaymentStatus::FAILED,
            'REFUNDED' => PaymentStatus::REFUNDED,
            default => PaymentStatus::PENDING,
        };
        $amount = data_get($data, 'order.amount');

        return new PaymentStatus(
            state: $state,
            amount: is_numeric($amount) ? (int) round((float) $amount) : null,
            method: (string) (data_get($data, 'service.id') ?: '') ?: null,
            channel: (string) (data_get($data, 'channel.id') ?: data_get($data, 'acquirer.id') ?: '') ?: null,
            reference: (string) (data_get($data, 'order.invoice_number') ?: '') ?: null,
            raw: $data,
        );
    }

    /** DOKU Checkout has no merchant balance API (balance only in the DOKU dashboard). */
    public function getBalance(): ?GatewayBalance
    {
        return null;
    }

    public function supportsDisbursement(): bool
    {
        return false;
    }

    public function disburse(DisbursementRequest $request): DisbursementResult
    {
        throw new RuntimeException('DOKU belum dipakai untuk transfer otomatis di Hellom.');
    }

    public function supportsAccountValidation(): bool
    {
        return false;
    }

    public function validateBankAccount(string $bankCode, string $accountNumber): ?string
    {
        return null;
    }
}
