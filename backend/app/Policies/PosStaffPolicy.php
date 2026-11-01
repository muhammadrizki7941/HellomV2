<?php

namespace App\Policies;

use App\Models\PosStaff;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Pos\PosRoles;

class PosStaffPolicy
{
    public function viewAny(User $user): bool
    {
        $organizationId = (int) ($user->current_organization_id ?? 0);

        return $organizationId > 0
            && ($this->isOrganizationAdmin($user, $organizationId) || $this->outletAdmin($user, $organizationId) !== null);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, PosStaff $target): bool
    {
        return $this->canManageTarget($user, $target);
    }

    public function delete(User $user, PosStaff $target): bool
    {
        return $this->canManageTarget($user, $target);
    }

    public function resetPassword(User $user, PosStaff $target): bool
    {
        return $this->canManageTarget($user, $target);
    }

    private function canManageTarget(User $user, PosStaff $target): bool
    {
        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0 || !$this->belongsToOrganization($target, $organizationId)) {
            return false;
        }
        if ($this->isOrganizationAdmin($user, $organizationId)) {
            return true;
        }

        $actor = $this->outletAdmin($user, $organizationId);
        if (!$actor || $target->businessRole() !== 'cashier') {
            return false;
        }

        return array_intersect($actor->assignedOutletIds(), $target->assignedOutletIds()) !== [];
    }

    private function isOrganizationAdmin(User $user, int $organizationId): bool
    {
        return PosRoles::isOrganizationAdminMembership((string) $user->organizations()
            ->where('organizations.id', $organizationId)
            ->first()?->pivot?->role);
    }

    private function outletAdmin(User $user, int $organizationId): ?PosStaff
    {
        $staff = PosStaff::query()
            ->forOrganization($organizationId)
            ->where('linked_user_id', $user->id)
            ->where('employment_status', 'active')
            ->orderByDesc('id')
            ->first();

        return $staff instanceof PosStaff && $staff->businessRole() === 'outlet_admin' ? $staff : null;
    }

    private function belongsToOrganization(PosStaff $staff, int $organizationId): bool
    {
        if ($staff->organization_id !== null) {
            return (int) $staff->organization_id === $organizationId;
        }

        return Outlet::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($staff): void {
                if ($staff->outlet_id) {
                    $query->whereKey((int) $staff->outlet_id);
                }
                $query->orWhere('tenant_slug', (string) $staff->tenant_id);
            })
            ->exists();
    }
}
