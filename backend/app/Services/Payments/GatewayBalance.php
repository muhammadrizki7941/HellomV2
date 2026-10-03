<?php

namespace App\Services\Payments;

/** Money held in Hellom's own account at a provider, as reported by its API. */
final class GatewayBalance
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $provider,
        public readonly int $available,
        public readonly ?int $pending = null,
        public readonly array $raw = [],
    ) {
    }
}
