<?php

namespace App\Services\Payments\Gateways;

use App\Services\Hellom\IpaymuService;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Payments\ChargeRequest;
use App\Services\Payments\ChargeResult;
use App\Services\Payments\DisbursementRequest;
use App\Services\Payments\DisbursementResult;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentStatus;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * iPaymu (the active gateway). Notifications carry our callback token in the URL (newer
 * iPaymu accounts also send an X-Signature, which we do not rely on). The token only lets
 * a notification in — the payment is then confirmed with iPaymu's own transaction API
 * (status, amount, our reference) before anything is marked paid.
 */
final class IpaymuGateway implements PaymentGateway
{
    public function __construct(
        private readonly IpaymuService $api,
        private readonly IpaymuSettingsService $settings,
    ) {
    }

    public function name(): string
    {
        return 'ipaymu';
    }

    public function isReady(): bool
    {
        return $this->settings->isReady();
    }

    public function createCharge(ChargeRequest $request): ChargeResult
    {
        $notifyUrl = $this->notifyUrl($request->notifyContext);
        $methods = $this->settings->enabledPaymentMethods();

        // QRIS-only: a direct charge so the buyer gets a QR on our own page.
        if (count($methods) === 1 && in_array('qris', $methods, true)) {
            $session = $this->api->createDirectPayment([
                'name' => $request->buyerName !== '' ? $request->buyerName : 'Pembeli',
                'email' => $request->buyerEmail,
                'phone' => $request->buyerPhone ?: '08000000000',
                'amount' => $request->amount,
                'referenceId' => $request->reference,
                'paymentMethod' => 'qris',
                'paymentChannel' => 'qris',
                'comments' => 'Pembelian: ' . $request->productName,
                'returnUrl' => $request->returnUrl,
                'notifyUrl' => $notifyUrl,
            ]);

            return new ChargeResult(
                provider: 'ipaymu',
                mode: 'qris',
                paymentUrl: null,
                gatewayRef: (string) (data_get($session, 'Data.SessionId') ?: data_get($session, 'Data.SessionID') ?: ''),
                transactionId: (string) (data_get($session, 'Data.TransactionId') ?: ''),
                qrImageUrl: (string) (data_get($session, 'Data.QrImage') ?: data_get($session, 'Data.qr_image') ?: data_get($session, 'Data.Url') ?: ''),
                qrString: (string) (data_get($session, 'Data.QrString') ?: data_get($session, 'Data.QrContent') ?: data_get($session, 'Data.qr_string') ?: ''),
                raw: $session,
            );
        }

        $session = $this->api->createRedirectPayment([
            'product' => [$request->productName],
            'qty' => [1],
            'price' => [$request->amount],
            'paymentMethod' => $methods,
            'referenceId' => $request->reference,
            'description' => ['Pembelian: ' . $request->productName],
            'buyerName' => $request->buyerName,
            'buyerEmail' => $request->buyerEmail,
            'buyerPhone' => (string) ($request->buyerPhone ?? ''),
            'returnUrl' => $request->returnUrl,
            'cancelUrl' => $request->returnUrl,
            'notifyUrl' => $notifyUrl,
        ]);

        return new ChargeResult(
            provider: 'ipaymu',
            mode: 'redirect',
            paymentUrl: (string) (data_get($session, 'Data.Url') ?: data_get($session, 'Url') ?: '') ?: null,
            gatewayRef: (string) (data_get($session, 'Data.SessionID') ?: data_get($session, 'Data.SessionId') ?: ''),
            transactionId: (string) (data_get($session, 'Data.TransactionId') ?: '') ?: null,
            raw: $session,
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        $expected = (string) ($this->settings->getConfig()['callback_token'] ?? '');
        $given = (string) ($request->query('token') ?: $request->header('X-IPAYMU-TOKEN', ''));

        return $expected !== '' && hash_equals($expected, $given);
    }

    public function getStatus(string $reference, ?string $gatewayRef, ?string $transactionId): PaymentStatus
    {
        if ($transactionId === null || $transactionId === '') {
            return PaymentStatus::unknown('iPaymu needs a transaction id to check status');
        }

        $response = $this->api->checkTransaction($transactionId);
        $data = (array) (data_get($response, 'Data') ?: data_get($response, 'data') ?: []);
        if ($data === []) {
            return PaymentStatus::unknown('empty iPaymu response');
        }

        // Codes from docs.ipaymu.com/en/docs/transaction/check-transaction: 0 pending, 1 success,
        // 2 cancelled, 3 refund, 4 error, 5 failed, 6 success-unsettled, 7 escrow, -2 expired.
        // The numeric code wins; the text is only a fallback when no code is sent.
        // Escrow (7) stays pending: the money is not released to the merchant yet.
        $code = data_get($data, 'Status', data_get($data, 'StatusCode'));
        $code = is_numeric($code) ? (string) (int) $code : null;
        $text = strtolower(trim((string) (data_get($data, 'StatusDesc') ?: data_get($data, 'StatusDescription') ?: '')));
        $state = $code !== null
            ? match ($code) {
                '1', '6' => PaymentStatus::PAID,
                '-2' => PaymentStatus::EXPIRED,
                '3' => PaymentStatus::REFUNDED,
                '2', '4', '5' => PaymentStatus::FAILED,
                default => PaymentStatus::PENDING,
            }
            : match (true) {
                in_array($text, ['berhasil', 'success', 'successful', 'paid'], true) => PaymentStatus::PAID,
                in_array($text, ['expired', 'kadaluarsa', 'kedaluwarsa'], true) => PaymentStatus::EXPIRED,
                $text === 'refund' => PaymentStatus::REFUNDED,
                in_array($text, ['batal', 'gagal', 'failed', 'cancelled', 'error'], true) => PaymentStatus::FAILED,
                default => PaymentStatus::PENDING,
            };

        $amount = data_get($data, 'Amount', data_get($data, 'amount'));
        $fee = data_get($data, 'Fee', data_get($data, 'fee'));

        return new PaymentStatus(
            state: $state,
            amount: is_numeric($amount) ? (int) round((float) $amount) : null,
            fee: is_numeric($fee) ? (int) round((float) $fee) : null,
            method: (string) (data_get($data, 'PaymentMethod') ?: data_get($data, 'Via') ?: data_get($data, 'TypeDesc') ?: '') ?: null,
            channel: (string) (data_get($data, 'PaymentChannel') ?: data_get($data, 'Channel') ?: '') ?: null,
            reference: (string) (data_get($data, 'ReferenceId') ?: data_get($data, 'reference_id') ?: '') ?: null,
            transactionId: (string) (data_get($data, 'TransactionId') ?: $transactionId),
            raw: $data,
        );
    }

    public function supportsDisbursement(): bool
    {
        return false;
    }

    public function disburse(DisbursementRequest $request): DisbursementResult
    {
        throw new RuntimeException('iPaymu belum mendukung transfer otomatis di Hellom. Gunakan mode penarikan manual.');
    }

    public function supportsAccountValidation(): bool
    {
        return false;
    }

    public function validateBankAccount(string $bankCode, string $accountNumber): ?string
    {
        return null;
    }

    /** @param array<string, scalar> $context */
    private function notifyUrl(array $context): string
    {
        $query = array_filter([...$context, 'token' => (string) ($this->settings->getConfig()['callback_token'] ?? '')], fn ($v) => $v !== '' && $v !== null);

        return url('/api/v1/hellom/webhooks/ipaymu') . '?' . http_build_query($query);
    }
}
