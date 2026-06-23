<?php

namespace App\Services\Hellom;

use App\Models\Organization;
use Database\Seeders\ProductPurchaseSettingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PosProvisioningService
{
    public function ensureProvisionedForPos(int $organizationId): ?Organization
    {
        return DB::transaction(function () use ($organizationId): ?Organization {
            $organization = Organization::query()
                ->lockForUpdate()
                ->find($organizationId);

            if (!$organization instanceof Organization) {
                return null;
            }

            $updates = [];

            if (!is_string($organization->pos_tenant_slug) || $organization->pos_tenant_slug === '') {
                $updates['pos_tenant_slug'] = $this->nextTenantSlug($organization);
                $updates['pos_provisioned_at'] = now();
            }

            if (!is_string($organization->pos_tenant_name) || $organization->pos_tenant_name === '') {
                $updates['pos_tenant_name'] = (string) $organization->name;
            }

            if ($updates !== []) {
                $organization->forceFill($updates)->save();
            }

            ProductPurchaseSettingSeeder::createDefaultsForOrganization((int) $organization->id);

            $organization = $organization->fresh() ?: $organization;

            // Make sure the organization has its primary outlet (multi-outlet foundation).
            app(\App\Services\OutletService::class)->ensurePrimaryOutlet($organization);

            return $organization;
        });
    }

    private function nextTenantSlug(Organization $organization): string
    {
        $baseSlug = Str::slug((string) $organization->slug);

        if ($baseSlug === '') {
            $baseSlug = 'org-' . $organization->id;
        }

        $slug = $baseSlug;
        $counter = 2;

        while (Organization::query()
            ->where('id', '!=', $organization->id)
            ->where('pos_tenant_slug', $slug)
            ->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
