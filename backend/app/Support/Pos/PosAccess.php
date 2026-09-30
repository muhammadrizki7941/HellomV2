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
            return ['is_cashier' => false];
        }

        $isPlatform = in_array((string) $user->role, ['super_admin', 'tenant_admin'], true);
        $pivotRole = (string) ($user->organizations()->where('organizations.id', (int) $organization->id)->first()?->pivot?->role ?? '');
        if ($isPlatform || in_array($pivotRole, ['owner', 'admin', 'super_admin'], true)) {
            return ['is_cashier' => false];
        }

        $staff = PosStaff::query()
            ->where('organization_id', (int) $organization->id)
            ->where('linked_user_id', (int) $user->id)
            ->where('employment_status', 'active')
            ->orderByDesc('id')
            ->first();
        if (!$staff instanceof PosStaff) {
            return ['is_cashier' => false];
        }

        $outlet = $staff->resolveBoundOutlet();

        return [
            'is_cashier' => true,
            'staff_id' => (int) $staff->id,
            'pos_role' => (string) $staff->role,
            'permissions' => PosPermissions::forStaff($staff),
            'outlet_id' => $outlet?->id,
            'outlet_name' => $outlet?->name,
            'tenant_slug' => (string) ($outlet?->tenant_slug ?? $staff->tenant_id),
        ];
    }
}
