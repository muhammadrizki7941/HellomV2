<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    /**
     * Resolve the user's pivot role inside the given organization.
     */
    private function pivotRole(User $user, Organization $organization): ?string
    {
        $member = $organization->users()->where('users.id', $user->id)->first();
        return $member ? (string) ($member->pivot->role ?? 'member') : null;
    }

    /** Any authenticated user may list their own organizations. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** User must belong to the organization. */
    public function view(User $user, Organization $organization): bool
    {
        return $this->pivotRole($user, $organization) !== null;
    }

    /** Only owner or admin may update organization settings. */
    public function update(User $user, Organization $organization): bool
    {
        return in_array($this->pivotRole($user, $organization), ['owner', 'admin'], true);
    }

    /** Only the owner may delete an organization. */
    public function delete(User $user, Organization $organization): bool
    {
        return $this->pivotRole($user, $organization) === 'owner';
    }

    /** Only owner or admin may manage team members. */
    public function manageTeam(User $user, Organization $organization): bool
    {
        return in_array($this->pivotRole($user, $organization), ['owner', 'admin'], true);
    }

    /** Only owner or admin may manage billing / subscriptions. */
    public function manageBilling(User $user, Organization $organization): bool
    {
        return in_array($this->pivotRole($user, $organization), ['owner', 'admin'], true);
    }

    /** Any member may use apps entitled to the organization. */
    public function useApps(User $user, Organization $organization): bool
    {
        return $this->pivotRole($user, $organization) !== null;
    }
}
