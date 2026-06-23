<?php

namespace App\Services;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\Plan;
use Illuminate\Support\Str;
use RuntimeException;

class OutletService
{
    /**
     * Effective outlet quota for an organization:
     * per-org override (set by Hellom owner) wins, otherwise the active POS
     * plan's max_outlets, otherwise 1.
     */
    public function effectiveMaxOutlets(Organization $organization): int
    {
        if ($organization->max_outlets_override !== null) {
            return max(1, (int) $organization->max_outlets_override);
        }

        $planMax = null;
        $appId = AppCatalog::query()->where('slug', 'pos')->value('id');
        if ($appId) {
            $entitlement = Entitlement::query()
                ->where('organization_id', $organization->id)
                ->where('app_id', $appId)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();

            if ($entitlement?->plan_id) {
                $planMax = Plan::query()->whereKey($entitlement->plan_id)->value('max_outlets');
            }
        }

        return max(1, (int) ($planMax ?? 1));
    }

    public function outletCount(Organization $organization): int
    {
        return Outlet::query()->where('organization_id', $organization->id)->count();
    }

    public function canAddOutlet(Organization $organization): bool
    {
        return $this->outletCount($organization) < $this->effectiveMaxOutlets($organization);
    }

    /**
     * Guarantee the organization has a primary outlet (used during POS
     * provisioning and as the fallback active outlet).
     */
    public function ensurePrimaryOutlet(Organization $organization): Outlet
    {
        $primary = Outlet::query()
            ->where('organization_id', $organization->id)
            ->where('is_primary', true)
            ->first();
        if ($primary) {
            return $primary;
        }

        // Promote an existing outlet if one exists but none is flagged primary.
        $existing = Outlet::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->first();
        if ($existing) {
            $existing->update(['is_primary' => true]);
            return $existing;
        }

        return Outlet::create([
            'organization_id' => $organization->id,
            'name' => $organization->pos_tenant_name ?: trim(($organization->name ?? 'Outlet') . ' - Outlet Utama'),
            'slug' => 'utama',
            'tenant_slug' => $organization->pos_tenant_slug ?: $organization->slug,
            'phone' => $organization->phone,
            'email' => $organization->email,
            'address' => $organization->address,
            'is_primary' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * Resolve the active outlet for a request. Falls back to the primary outlet
     * when no (valid) outlet id is supplied.
     */
    public function resolveActiveOutlet(Organization $organization, int|string|null $outletId): Outlet
    {
        $primary = $this->ensurePrimaryOutlet($organization);

        if ($outletId !== null && $outletId !== '' && (int) $outletId > 0) {
            $outlet = Outlet::query()
                ->where('organization_id', $organization->id)
                ->where('id', (int) $outletId)
                ->where('is_active', true)
                ->first();
            if ($outlet) {
                return $outlet;
            }
        }

        return $primary;
    }

    /**
     * Create a new outlet. Enforces the plan quota and generates unique
     * slug + tenant_slug (the per-outlet POS scoping key).
     *
     * @throws RuntimeException when the outlet quota is reached.
     */
    public function createOutlet(Organization $organization, array $data): Outlet
    {
        if (!$this->canAddOutlet($organization)) {
            throw new RuntimeException('OUTLET_LIMIT_REACHED');
        }

        $name = trim((string) ($data['name'] ?? 'Outlet Baru'));

        return Outlet::create([
            'organization_id' => $organization->id,
            'name' => $name !== '' ? $name : 'Outlet Baru',
            'slug' => $this->uniqueOutletSlug($organization, $name),
            'tenant_slug' => $this->uniqueTenantSlug($organization, $name),
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'description' => $data['description'] ?? null,
            'is_primary' => false,
            'is_active' => true,
            'sort_order' => (int) (Outlet::query()->where('organization_id', $organization->id)->max('sort_order') ?? 0) + 1,
        ]);
    }

    private function uniqueOutletSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($name) ?: 'outlet';
        $slug = $base;
        $counter = 2;

        while (Outlet::query()
            ->where('organization_id', $organization->id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * tenant_slug is the global POS scoping key, so it must not collide with
     * another outlet's tenant_slug or any organization's pos_tenant_slug.
     */
    private function uniqueTenantSlug(Organization $organization, string $name): string
    {
        $orgBase = Str::slug((string) ($organization->pos_tenant_slug ?: $organization->slug)) ?: ('org-' . $organization->id);
        $base = $orgBase . '-' . (Str::slug($name) ?: 'outlet');
        $slug = $base;
        $counter = 2;

        while (
            Outlet::query()->where('tenant_slug', $slug)->exists()
            || Organization::query()->where('pos_tenant_slug', $slug)->exists()
        ) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
