<?php

namespace App\Http\Controllers\Api\V1\Hellom\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seller endpoints of Hellom Page (money, products, orders): the user's current
 * organization, and only its owners/admins (or a super admin).
 */
trait ResolvesSellerOrganization
{
    /** @return array{0: ?Organization, 1: ?JsonResponse} */
    protected function sellerOrganization(Request $request, string $deniedMessage = 'Hanya pemilik/admin toko yang bisa membuka menu ini'): array
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return [null, $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401)];
        }
        $organization = Organization::query()->find((int) $user->current_organization_id);
        if (!$organization) {
            return [null, $this->fail('Pilih organisasi dulu', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403)];
        }
        $role = (string) ($user->organizations()->where('organizations.id', $organization->id)->first()?->pivot?->role ?? '');
        if (!in_array($role, ['owner', 'admin'], true) && (string) $user->role !== 'super_admin') {
            return [null, $this->fail($deniedMessage, ['code' => 'INSUFFICIENT_ROLE'], 403)];
        }

        return [$organization, null];
    }
}
