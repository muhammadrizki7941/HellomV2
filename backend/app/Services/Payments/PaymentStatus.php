<?php

namespace App\Services\Payments;

final class PaymentStatus
{
    public const PAID = 'paid';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const EXPIRED = 'expired';
    public const REFUNDED = 'refunded';
    public const UNKNOWN = 'unknown';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $state,
        public readonly ?int $amount = null,          // amount the buyer paid, as reported by the provider
        public readonly ?int $fee = null,             // provider fee, when reported
        public readonly ?string $method = null,
        public readonly ?string $channel = null,
        public readonly ?string $reference = null,    // our reference as known by the provider
        public readonly ?string $transactionId = null,
        public readonly array $raw = [],
    ) {
    }

    public static function unknown(string $why = ''): self
    {
        return new self(self::UNKNOWN, raw: ['reason' => $why]);
    }
}
