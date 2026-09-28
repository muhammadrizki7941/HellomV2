<?php

namespace App\Services\Payments;

final class ChargeResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $provider,
        public readonly string $mode,                 // redirect | qris
        public readonly ?string $paymentUrl,
        public readonly ?string $gatewayRef,
        public readonly ?string $transactionId = null,
        public readonly ?string $qrImageUrl = null,
        public readonly ?string $qrString = null,
        public readonly array $raw = [],
    ) {
    }
}
