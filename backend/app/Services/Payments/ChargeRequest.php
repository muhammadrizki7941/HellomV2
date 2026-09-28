<?php

namespace App\Services\Payments;

final class ChargeRequest
{
    /** @param array<string, scalar> $notifyContext query parameters added to the notification URL */
    public function __construct(
        public readonly string $reference,
        public readonly int $amount,
        public readonly string $productName,
        public readonly string $buyerName,
        public readonly string $buyerEmail,
        public readonly ?string $buyerPhone,
        public readonly string $returnUrl,
        public readonly array $notifyContext = [],
    ) {
    }
}
