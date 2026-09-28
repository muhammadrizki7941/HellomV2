<?php

namespace App\Services\Pos;

use RuntimeException;

/** A cart/order that cannot be priced as sent; `problems` lists the affected items. */
class PricingException extends RuntimeException
{
    /** @param list<array<string,mixed>> $problems */
    public function __construct(string $message, public readonly array $problems = [], public readonly string $errorCode = 'ORDER_INVALID', public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
