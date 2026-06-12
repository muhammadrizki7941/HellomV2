<?php

namespace App\Policies;

use App\Models\OrganizationLandingPage;
use App\Models\User;

class LandingPagePolicy
{
    /** Any authenticated user may list pages (scoped by org in controller). */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** User must belong to the same org that owns the page. */
    public function view(User $user, OrganizationLandingPage $page): bool
    {
        return $user->organizations()->where('organizations.id', $page->organization_id)->exists();
    }

    /** User must belong to the page's org. */
    public function update(User $user, OrganizationLandingPage $page): bool
    {
        return $user->organizations()->where('organizations.id', $page->organization_id)->exists();
    }

    /** Only owner or admin of the page's org may delete. */
    public function delete(User $user, OrganizationLandingPage $page): bool
    {
        $member = $user->organizations()->where('organizations.id', $page->organization_id)->first();
        if (!$member) {
            return false;
        }
        return in_array((string) ($member->pivot->role ?? 'member'), ['owner', 'admin'], true);
    }
}
