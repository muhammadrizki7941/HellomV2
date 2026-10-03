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

    /**
     * Channels the buyer picks on our own checkout page: every enabled direct channel
     * (QRIS, VA per bank, Indomaret/Alfamart) — the same list Hellom's own product
     * checkout offers. "other" (iPaymu's hosted page) only when no direct channel is on.
     */
    public function paymentOptions(): array
    {
        $direct = array_keys($this->settings->enabledDirectChannels());
        if ($direct !== []) {
            return $direct;
        }

        return $this->settings->enabledPaymentMethods() !== [] ? ['other'] : [];
    }

    public function createCharge(ChargeRequest $request): ChargeResult
    {
        $notifyUrl = $this->notifyUrl($request->notifyContext);
        $channels = $this->settings->enabledDirectChannels();
        $key = (string) ($request->preferredMethod ?? '');
        if (!isset($channels[$key]) && $key !== 'other') {
            $key = (string) (array_key_first($channels) ?? 'other');
        }

        // Paid on our own page: QR or VA/retail code, like Hellom's own product checkout.
        if (isset($channels[$key])) {
            return $this->directCharge($request, $notifyUrl, $channels[$key]);
        }

        // Only when no direct channel is enabled: iPaymu's hosted payment page.
        $payload = [
            'product' => [$request->productName],
            'qty' => [1],
            'price' => [$request->amount],
            'paymentMethod' => $this->settings->enabledPaymentMethods(),
            'referenceId' => $request->reference,
            'description' => ['Pembelian: ' . $request->productName],
            'buyerName' => $request->buyerName,
            'buyerEmail' => $request->buyerEmail,
            'returnUrl' => $request->returnUrl,
            'cancelUrl' => $request->returnUrl,
            'notifyUrl' => $notifyUrl,
        ];
        // An empty buyerPhone is rejected by iPaymu; omit it like the subscription checkout does.
        if (trim((string) $request->buyerPhone) !== '') {
            $payload['buyerPhone'] = (string) $request->buyerPhone;
        }
        $session = $this->api->createRedirectPayment($payload);
        $this->assertAccepted($session);

        return new ChargeResult(
            provider: 'ipaymu',
            mode: 'redirect',
            paymentUrl: (string) (data_get($session, 'Data.Url') ?: data_get($session, 'Url') ?: '') ?: null,
            gatewayRef: (string) (data_get($session, 'Data.SessionID') ?: data_get($session, 'Data.SessionId') ?: ''),
            transactionId: (string) (data_get($session, 'Data.TransactionId') ?: '') ?: null,
            raw: $session,
        );
    }

    /**
     * iPaymu direct charge — same request and response handling as
     * ProductCheckoutService::createIpaymuDirectCharge (Hellom's own products).
     *
     * @param array{0:string,1:string,2:string,3:string} $channel [method, channel, label, group]
     */
    private function directCharge(ChargeRequest $request, string $notifyUrl, array $channel): ChargeResult
    {
        [$method, $channelCode, $label] = $channel;
        $phone = preg_replace('/\D/', '', (string) $request->buyerPhone);

        $response = $this->api->createDirectPayment([
            'name' => $request->buyerName !== '' ? $request->buyerName : 'Pembeli',
            'phone' => is_string($phone) && $phone !== '' ? $phone : '081234567890',
            'email' => $request->buyerEmail,
            'amount' => $request->amount,
            'notifyUrl' => $notifyUrl,
            'referenceId' => $request->reference,
            'paymentMethod' => $method,
            'paymentChannel' => $channelCode,
            'comments' => 'Pembelian: ' . $request->productName,
        ]);

        $data = (array) (data_get($response, 'Data') ?: data_get($response, 'data') ?: []);
        $status = (int) (data_get($response, 'Status') ?? data_get($response, 'status') ?? 0);
        if ($status !== 200 && $data === []) {
            throw new RuntimeException('iPaymu menolak permintaan (' . $status . '): ' . (string) (data_get($response, 'Message') ?: data_get($response, 'message') ?: 'tanpa pesan'));
        }

        $paymentNo = (string) (data_get($data, 'PaymentNo') ?: data_get($data, 'paymentNo') ?: '');
        $qrString = (string) (data_get($data, 'QrString') ?: data_get($data, 'qrString') ?: '');
        $qrImage = (string) (data_get($data, 'QrImage') ?: data_get($data, 'qrImage') ?: data_get($data, 'QrTemplate') ?: '');
        // For QRIS the EMVCo payload often arrives in PaymentNo rather than QrString.
        if ($method === 'qris') {
            if ($qrString === '' && $paymentNo !== '') {
                $qrString = $paymentNo;
            }
            $paymentNo = '';
            if ($qrString === '' && $qrImage === '') {
                throw new RuntimeException('iPaymu tidak mengirim kode QRIS.');
            }
        } elseif ($paymentNo === '') {
            throw new RuntimeException('iPaymu tidak mengirim nomor pembayaran untuk ' . $label . '.');
        }

        $expiresAt = null;
        $expired = (string) (data_get($data, 'Expired') ?: data_get($data, 'expired') ?: '');
        if ($expired !== '') {
            try {
                $expiresAt = \Illuminate\Support\Carbon::parse($expired, 'Asia/Jakarta')->toIso8601String();
            } catch (\Throwable) {
                $expiresAt = null;
            }
        }

        return new ChargeResult(
            provider: 'ipaymu',
            mode: $method === 'qris' ? 'qris' : 'va',
            paymentUrl: null,
            gatewayRef: (string) (data_get($data, 'SessionId') ?: data_get($data, 'SessionID') ?: ''),
            transactionId: (string) (data_get($data, 'TransactionId') ?: data_get($data, 'transactionId') ?: '') ?: null,
            qrImageUrl: $qrImage !== '' ? $qrImage : null,
            qrString: $qrString !== '' ? $qrString : null,
            raw: $response,
            vaNumber: $paymentNo !== '' ? $paymentNo : null,
            channelLabel: $label,
            expiresAt: $expiresAt,
        );
    }

    /**
     * iPaymu often answers HTTP 200 with the real result in the body
     * ({"Status":401,"Message":"..."}): treat a non-200 Status as an error so the
     * reason is logged instead of surfacing later as an empty payment link.
     *
     * @param array<string, mixed> $response
     */
    private function assertAccepted(array $response): void
    {
        $status = data_get($response, 'Status');
        if ($status !== null && (int) $status !== 200) {
            throw new RuntimeException('iPaymu menolak permintaan (' . (int) $status . '): ' . (string) (data_get($response, 'Message') ?: 'tanpa pesan'));
        }
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
