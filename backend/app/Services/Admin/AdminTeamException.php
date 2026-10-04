<?php

namespace App\Services\Admin;

use RuntimeException;

/** Refusal from PlatformAdminTeam with an error code and HTTP status for the API. */
final class AdminTeamException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
