<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Cache\TenantCache;
use App\Models\Tenant;

class ClearTenantCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:clear-tenant {--tenant= : Specific tenant slug to clear cache for} {--all : Clear cache for all tenants}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear tenant-specific cache for performance optimization';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenantSlug = $this->option('tenant');
        $clearAll = $this->option('all');

        if ($tenantSlug) {
            // Clear cache for specific tenant
            $tenant = Tenant::where('slug', $tenantSlug)->first();
            if (!$tenant) {
                $this->error("Tenant with slug '{$tenantSlug}' not found.");
                return 1;
            }

            $this->clearTenantCache($tenant);
            $this->info("Cache cleared for tenant: {$tenant->name} ({$tenant->slug})");
        } elseif ($clearAll) {
            // Clear cache for all tenants
            $tenants = Tenant::all();
            $this->info("Clearing cache for {$tenants->count()} tenants...");

            foreach ($tenants as $tenant) {
                $this->clearTenantCache($tenant);
                $this->line("✓ Cleared cache for: {$tenant->name} ({$tenant->slug})");
            }

            $this->info("All tenant caches cleared successfully!");
        } else {
            $this->error("Please specify --tenant=slug or --all option.");
            return 1;
        }

        return 0;
    }

    /**
     * Clear cache for a specific tenant
     */
    private function clearTenantCache(Tenant $tenant): void
    {
        // Create tenant context for this tenant
        $tenantContext = new \App\Services\Tenancy\TenantContext(
            id: $tenant->id,
            slug: $tenant->slug,
            name: $tenant->name,
            plan: $tenant->plan,
            status: $tenant->status,
            trialStartedAt: $tenant->trial_started_at,
            activeUntil: $tenant->active_until,
            subdomain: $tenant->subdomain,
            customDomain: $tenant->custom_domain
        );

        // Bind tenant context and clear cache
        app()->bind(\App\Services\Tenancy\TenantContext::class, fn() => $tenantContext);

        $cache = app(TenantCache::class);
        $cache->clearTenantCache();

        // Also clear brand settings cache
        \App\Models\BrandSetting::forgetCache();
    }
}
