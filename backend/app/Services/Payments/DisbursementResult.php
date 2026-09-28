<?php

namespace App\Services\Payments;

final class DisbursementResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerRef,
        public readonly array $raw = [],
    ) {
    }
}
