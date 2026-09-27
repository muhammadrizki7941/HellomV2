<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TenantResolver
{
    public function resolveFromSlug(string $slug): ?TenantContext
    {
        $normalizedSlug = strtolower(trim($slug));

        if ($normalizedSlug === '') {
            return null;
        }

        if (Schema::hasTable('tenants')) {
            $tenant = Tenant::query()->where('slug', $normalizedSlug)->first();
            if ($tenant) {
                return new TenantContext(
                    id: (int) $tenant->id,
                    slug: (string) $tenant->slug,
                    name: (string) $tenant->name,
                    plan: (string) ($tenant->plan ?? 'trial'),
                    status: (string) ($tenant->status ?? 'active'),
                    trialStartedAt: $tenant->trial_started_at?->toDateString(),
                    activeUntil: $tenant->active_until?->toDateString(),
                    subdomain: $tenant->subdomain !== null ? (string) $tenant->subdomain : null,
                    customDomain: $tenant->custom_domain !== null ? (string) $tenant->custom_domain : null,
                );
            }
        }

        $allTenants = (array) config('tenancy.tenants', []);
        if (!isset($allTenants[$normalizedSlug]) || !is_array($allTenants[$normalizedSlug])) {
            return null;
        }

        $row = $allTenants[$normalizedSlug];

        return new TenantContext(
            id: null,
            slug: $normalizedSlug,
            name: (string) ($row['name'] ?? strtoupper($normalizedSlug)),
            plan: (string) ($row['plan'] ?? 'trial'),
            status: (string) ($row['status'] ?? 'active'),
            trialStartedAt: isset($row['trial_started_at']) ? (string) $row['trial_started_at'] : null,
            activeUntil: isset($row['active_until']) ? (string) $row['active_until'] : null,
            subdomain: isset($row['subdomain']) ? (string) $row['subdomain'] : null,
            customDomain: isset($row['custom_domain']) ? (string) $row['custom_domain'] : null,
        );
    }

    public function resolveFromRequest(Request $request): ?TenantContext
    {
        $routeTenant = $request->route('tenant');
        if (is_string($routeTenant) && $routeTenant !== '') {
            return $this->resolveFromSlug($routeTenant);
        }

        $path = (string) $request->getPathInfo();
        if (preg_match('#^/t/([^/]+)(?:/|$)#', $path, $matches) === 1) {
            return $this->resolveFromSlug((string) $matches[1]);
        }

        $host = strtolower((string) $request->getHost());
        $baseDomain = strtolower((string) config('tenancy.base_domain', 'localhost'));

        if ($host !== '' && $baseDomain !== '' && $host !== $baseDomain && str_ends_with($host, '.'.$baseDomain)) {
            $slug = substr($host, 0, -1 * (strlen($baseDomain) + 1));
            if (is_string($slug) && $slug !== '') {
                return $this->resolveFromSlug($slug);
            }
        }

        return null;
    }
}
