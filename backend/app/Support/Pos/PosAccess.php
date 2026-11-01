<?php

namespace App\Support\Pos;

use App\Models\Organization;
use App\Models\PosStaff;
use App\Models\User;

/**
 * POS access context for the SPA (login payload `pos_access` and GET /pos/me/access):
 * cashiers are routed straight into POS, locked to their outlet, and see only the features
 * their owner/admin allowed. Owners/admins get is_cashier=false (no limits).
 */
final class PosAccess
{
    public static function forUser(User $user): array
    {
        $organization = $user->currentOrganization;
        if (!$organization instanceof Organization) {
            return ['is_cashier' => false, 'organization_role' => null];
        }

        $isPlatform = PosRoles::isPlatformManager((string) $user->role);
        $pivotRole = (string) ($user->organizations()->where('organizations.id', (int) $organization->id)->first()?->pivot?->role ?? '');
        if ($isPlatform || $pivotRole === 'super_admin') {
            return ['is_cashier' => false, 'organization_role' => 'platform_admin'];
        }
        if (PosRoles::isOrganizationAdminMembership($pivotRole)) {
            return ['is_cashier' => false, 'organization_role' => PosRoles::ORG_ADMIN];
        }

        $staff = PosStaff::query()
            ->forOrganization((int) $organization->id)
            ->where('linked_user_id', (int) $user->id)
            ->where('employment_status', 'active')
            ->orderByDesc('id')
            ->first();
        if (!$staff instanceof PosStaff) {
            return ['is_cashier' => false, 'organization_role' => 'member'];
        }

        $outlet = $staff->resolveBoundOutlet();
        $assignedOutlets = $staff->outlets()
            ->where('outlets.is_active', true)
            ->orderBy('outlets.sort_order')
            ->orderBy('outlets.id')
            ->get(['outlets.id', 'outlets.name']);
        if ($assignedOutlets->isEmpty() && $outlet) {
            $assignedOutlets = collect([(object) $outlet->only(['id', 'name'])]);
        }

        return [
            'is_cashier' => true,
            'organization_role' => 'staff',
            'staff_id' => (int) $staff->id,
            'pos_role' => (string) $staff->role,
            'business_role' => $staff->businessRole(),
            'permissions' => PosPermissions::forStaff($staff),
            'outlet_id' => $outlet?->id,
            'outlet_name' => $outlet?->name,
            'outlets' => $assignedOutlets->map(fn ($assignedOutlet) => [
                'id' => (int) $assignedOutlet->id,
                'name' => (string) $assignedOutlet->name,
            ])->values(),
            'tenant_slug' => (string) ($outlet?->tenant_slug ?? $staff->tenant_id),
        ];
    }
}
