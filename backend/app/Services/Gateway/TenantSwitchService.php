<?php

namespace App\Services\Gateway;

final class TenantSwitchService
{
    /**
     * @param array<string,mixed> $user
     */
    public function canSwitchToTenant(array $user, string $tenantSlug): bool
    {
        $role = (string) ($user['role'] ?? '');
        if ($role === 'super_admin') {
            return true;
        }

        if ($role !== 'tenant_admin') {
            return false;
        }

        $allowed = (array) ($user['allowed_tenants'] ?? []);

        return in_array('*', $allowed, true) || in_array($tenantSlug, $allowed, true);
    }
}
