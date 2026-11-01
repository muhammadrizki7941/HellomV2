<?php

namespace App\Support\Pos;

final class PosRoles
{
    public const ORG_ADMIN = 'org_admin';
    public const OUTLET_ADMIN = 'outlet_admin';
    public const CASHIER = 'cashier';

    public const LEGACY_OUTLET_ADMIN = 'admin';
    public const ORG_ADMIN_MEMBERSHIP_ROLES = ['owner', 'admin'];
    public const ORG_MANAGER_MEMBERSHIP_ROLES = ['owner', 'admin', 'super_admin'];
    public const PLATFORM_MANAGER_ROLES = ['super_admin', 'tenant_admin'];

    public static function isOrganizationAdminMembership(?string $role): bool
    {
        return in_array((string) $role, self::ORG_ADMIN_MEMBERSHIP_ROLES, true);
    }

    public static function isOrganizationManagerMembership(?string $role): bool
    {
        return in_array((string) $role, self::ORG_MANAGER_MEMBERSHIP_ROLES, true);
    }

    public static function isPlatformManager(?string $role): bool
    {
        return in_array((string) $role, self::PLATFORM_MANAGER_ROLES, true);
    }

    public static function isOutletAdmin(string $role): bool
    {
        return in_array($role, [self::OUTLET_ADMIN, self::LEGACY_OUTLET_ADMIN, 'owner'], true);
    }

    public static function staffRole(string $storedRole): string
    {
        return $storedRole === self::LEGACY_OUTLET_ADMIN ? self::OUTLET_ADMIN : self::CASHIER;
    }
}
