<?php

namespace App\Services\Payments;

final class DisbursementRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amount,
        public readonly string $bankCode,
        public readonly string $accountNumber,
        public readonly string $accountName,
        public readonly string $description,
    ) {
    }
}
