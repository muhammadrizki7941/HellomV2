<?php

namespace App\Services\Payments\Gateways;

use App\Services\Hellom\XenditService;
use App\Services\Hellom\XenditSettingsService;
use App\Services\Payments\ChargeRequest;
use App\Services\Payments\ChargeResult;
use App\Services\Payments\DisbursementRequest;
use App\Services\Payments\DisbursementResult;
use App\Services\Payments\GatewayBalance;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Xendit Payment Sessions (charges) and Payouts (disbursement). Webhooks carry X-CALLBACK-TOKEN. */
final class XenditGateway implements PaymentGateway
{
    public function __construct(
        private readonly XenditService $api,
        private readonly XenditSettingsService $settings,
    ) {
    }

    public function name(): string
    {
        return 'xendit';
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
        $session = $this->api->createPaymentSession([
            'reference_id' => $request->reference,
            'session_type' => 'PAY',
            'mode' => 'PAYMENT_LINK',
            'amount' => $request->amount,
            'currency' => 'IDR',
            'country' => 'ID',
            'locale' => 'id',
            'capture_method' => 'AUTOMATIC',
            'allow_save_payment_method' => 'DISABLED',
            'success_return_url' => $request->returnUrl,
            'cancel_return_url' => $request->returnUrl,
            'description' => 'Pembelian: ' . $request->productName,
            'items' => [[
                'reference_id' => 'landing_sale',
                'type' => 'DIGITAL_SERVICE',
                'name' => $request->productName,
                'net_unit_amount' => $request->amount,
                'quantity' => 1,
                'category' => 'LANDING_SALE',
            ]],
            'customer' => [
                'reference_id' => 'buyer_' . $request->reference,
                'type' => 'INDIVIDUAL',
                'email' => $request->buyerEmail,
                'individual_detail' => [
                    'given_names' => (string) Str::of($request->buyerName)->before(' ')->value(),
                    'surname' => (string) Str::of($request->buyerName)->after(' ')->value(),
                ],
            ],
            'metadata' => $request->notifyContext,
        ]);

        return new ChargeResult(
            provider: 'xendit',
            mode: 'redirect',
            paymentUrl: (string) (data_get($session, 'payment_link_url') ?: '') ?: null,
            gatewayRef: (string) (data_get($session, 'payment_session_id') ?: ''),
            raw: $session,
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        $expected = (string) ($this->settings->getConfig()['callback_token'] ?? '');

        return $expected !== '' && hash_equals($expected, (string) $request->header('X-CALLBACK-TOKEN', ''));
    }

    public function getStatus(string $reference, ?string $gatewayRef, ?string $transactionId): PaymentStatus
    {
        if ($gatewayRef === null || $gatewayRef === '') {
            return PaymentStatus::unknown('no payment session id');
        }
        $session = $this->api->getPaymentSession($gatewayRef);
        $status = strtoupper((string) data_get($session, 'status', ''));
        $state = match ($status) {
            'COMPLETED', 'SUCCEEDED', 'PAID' => PaymentStatus::PAID,
            'EXPIRED' => PaymentStatus::EXPIRED,
            'CANCELED', 'CANCELLED', 'FAILED' => PaymentStatus::FAILED,
            default => PaymentStatus::PENDING,
        };
        $amount = data_get($session, 'amount');

        return new PaymentStatus(
            state: $state,
            amount: is_numeric($amount) ? (int) round((float) $amount) : null,
            method: (string) (data_get($session, 'payment_method.type') ?: data_get($session, 'channel_code') ?: '') ?: null,
            reference: (string) (data_get($session, 'reference_id') ?: '') ?: null,
            transactionId: (string) (data_get($session, 'payment_id') ?: data_get($session, 'payment_request_id') ?: '') ?: null,
            raw: $session,
        );
    }

    public function getBalance(): ?GatewayBalance
    {
        if (!$this->isReady()) {
            return null;
        }
        $balance = $this->api->getBalance();
        $available = $balance['balance'] ?? $balance['available'] ?? null;

        return is_numeric($available) ? new GatewayBalance('xendit', (int) $available, isset($balance['pending']) ? (int) $balance['pending'] : null, $balance) : null;
    }

    public function supportsDisbursement(): bool
    {
        return $this->isReady();
    }

    public function disburse(DisbursementRequest $request): DisbursementResult
    {
        $code = strtoupper(trim($request->bankCode));
        $response = $this->api->createPayout([
            'reference_id' => $request->reference,
            'channel_code' => str_starts_with($code, 'ID_') ? $code : 'ID_' . $code,
            'channel_properties' => [
                'account_number' => $request->accountNumber,
                'account_holder_name' => $request->accountName,
            ],
            'amount' => $request->amount,
            'description' => $request->description,
            'currency' => 'IDR',
        ], $request->reference);

        return new DisbursementResult(true, (string) (data_get($response, 'id') ?: '') ?: null, $response);
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
