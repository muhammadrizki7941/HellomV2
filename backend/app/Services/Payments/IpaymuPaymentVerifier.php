<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;

/**
 * iPaymu notifications are not signed, so subscription, digital product and wallet
 * top-up payments are only booked after iPaymu's own transaction API confirms the
 * status, our reference and the amount (same rule as LandingPaymentService).
 *
 * The notify URL also carries an HMAC of its parameters (sig), so a notification
 * cannot be pointed at another organization, intent or purchase.
 */
class IpaymuPaymentVerifier
{
    /** Notify URL parameters covered by the signature. */
    private const SIGNED = ['purpose', 'organization_id', 'user_id', 'subscription_id', 'checkout_intent_id', 'invoice_id', 'purchase_id', 'product_id', 'reference_id'];

    public function __construct(private readonly GatewayRegistry $gateways)
    {
    }

    /** @param array<string, mixed> $params */
    public static function sign(array $params): string
    {
        // The URL builders drop empty and zero values, so they sign as ''.
        $payload = collect(self::SIGNED)->map(function (string $key) use ($params) {
            $value = (string) ($params[$key] ?? '');

            return $key . '=' . ($value === '0' ? '' : $value);
        })->implode('&');

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /**
     * True/false when the URL carries a signature, null for URLs created before signing
     * existed (still verified against the gateway, only not bound by signature).
     *
     * @param array<string, mixed> $query
     */
    public static function signatureValid(array $query): ?bool
    {
        $signature = (string) ($query['sig'] ?? '');
        if ($signature === '') {
            return null;
        }

        return hash_equals(self::sign($query), $signature);
    }

    /**
     * @param bool $trustedTransaction true when the transaction id comes from the gateway
     *                                 (webhook), false when a browser supplied it
     * @return array{ok: bool, reason: string, status: PaymentStatus}
     */
    public function verify(string $reference, ?int $expectedAmount, ?string $transactionId, bool $trustedTransaction): array
    {
        if ($reference === '' || $transactionId === null || $transactionId === '') {
            return ['ok' => false, 'reason' => 'unverifiable', 'status' => PaymentStatus::unknown('missing reference or transaction id')];
        }

        $status = $this->gateways->get('ipaymu')->getStatus($reference, null, $transactionId);
        $fail = fn (string $reason) => ['ok' => false, 'reason' => $reason, 'status' => $status];

        if ($status->state !== PaymentStatus::PAID) {
            return $fail($status->state);
        }
        if ($status->reference !== null && $status->reference !== $reference) {
            return $fail('reference_mismatch');
        }
        if ($status->reference === null && !$trustedTransaction) {
            return $fail('reference_unknown');
        }
        if ($expectedAmount !== null) {
            if ($status->amount === null) {
                return $fail('amount_unknown');
            }
            if ($status->amount !== $expectedAmount) {
                return $fail('amount_mismatch');
            }
        }
        if ($this->transactionUsedElsewhere((string) ($status->transactionId ?: $transactionId), $reference)) {
            return $fail('transaction_reused');
        }

        return ['ok' => true, 'reason' => 'paid', 'status' => $status];
    }

    /** One iPaymu payment pays for exactly one checkout, purchase, top-up or order. */
    private function transactionUsedElsewhere(string $transactionId, string $reference): bool
    {
        return DB::table('checkout_intents')->where('metadata->ipaymu->transaction_id', $transactionId)->where('intent_token', '!=', $reference)->exists()
            || DB::table('product_purchases')->where('payment_gateway', 'ipaymu')->where('payment_status', 'paid')->where('gateway_ref', $transactionId)->where('transaction_code', '!=', $reference)->exists()
            || DB::table('organization_wallet_transactions')->where('metadata->transaction_id', $transactionId)->where(fn ($q) => $q->whereNull('external_ref')->orWhere('external_ref', '!=', $reference))->exists()
            || DB::table('landing_page_orders')->where('gateway_trx_id', $transactionId)->exists();
    }
}
