<?php

namespace App\Services\Pos\Audit;

use RuntimeException;

final class OwnerPinException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 422, public readonly array $data = [])
    {
        parent::__construct($message);
    }
}
