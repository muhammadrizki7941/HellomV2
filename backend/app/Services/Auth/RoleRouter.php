<?php

namespace App\Services\Auth;

final class RoleRouter
{
    /** @param array<string,mixed> $user */
    public function postLoginPath(array $user): string
    {
        $role = (string) ($user['role'] ?? '');

        return match ($role) {
            'super_admin' => '/gateway/super',
            'tenant_admin' => '/gateway',
            default => '/login',
        };
    }
}
