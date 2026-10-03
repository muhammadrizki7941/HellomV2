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
    ) {
    }
}
