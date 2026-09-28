<?php

namespace App\Services\SellerFinance;

use RuntimeException;

/** A money rule said no (shown to the user as-is, in Indonesian). */
final class FinanceException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'FINANCE_RULE', public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
