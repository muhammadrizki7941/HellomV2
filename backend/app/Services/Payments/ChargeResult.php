<?php

namespace App\Services\Payments;

final class ChargeResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $provider,
        public readonly string $mode,                 // redirect | qris | va (VA or retail payment code)
        public readonly ?string $paymentUrl,
        public readonly ?string $gatewayRef,
        public readonly ?string $transactionId = null,
        public readonly ?string $qrImageUrl = null,
        public readonly ?string $qrString = null,
        public readonly array $raw = [],
        public readonly ?string $vaNumber = null,     // VA number / retail payment code (mode va)
        public readonly ?string $channelLabel = null, // e.g. "BCA Virtual Account", "QRIS"
        public readonly ?string $expiresAt = null,    // ISO 8601, as reported by the gateway
        public readonly ?int $total = null,           // what the buyer pays, when the provider reports it
        public readonly ?int $fee = null,             // provider fee, when reported at charge time
        public readonly ?string $sessionId = null,    // provider session id (hosted page / direct charge)
    ) {
    }
}
