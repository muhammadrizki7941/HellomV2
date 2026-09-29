<?php

namespace App\Services\Landing;

use App\Models\Organization;
use App\Models\OrganizationPayoutProfile;

/**
 * "Penjual Terverifikasi": the seller's KTP + payout account were approved by Hellom and
 * an owner of the shop has a verified email address.
 */
final class SellerTrust
{
    public function isVerified(int $organizationId): bool
    {
        $profileVerified = OrganizationPayoutProfile::query()
            ->where('organization_id', $organizationId)
            ->where('status', OrganizationPayoutProfile::STATUS_VERIFIED)
            ->exists();
        if (!$profileVerified) {
            return false;
        }

        return Organization::query()->whereKey($organizationId)
            ->whereHas('users', fn ($q) => $q->where('organization_user.role', 'owner')->whereNotNull('users.email_verified_at'))
            ->exists();
    }

    /** @return array{name: ?string, slug: ?string, username: string, verified: bool, suspended: bool} */
    public function publicSeller(Organization $organization): array
    {
        return [
            'name' => $organization->name,
            'slug' => $organization->slug,
            'username' => $organization->landingUsername(),
            'verified' => $this->isVerified((int) $organization->id),
            'suspended' => $organization->landing_suspended_at !== null,
        ];
    }
}
