<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Models\Tenant;

class TenantCacheStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:tenant-stats {--tenant= : Specific tenant slug to show stats for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show tenant-specific cache statistics and performance metrics';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenantSlug = $this->option('tenant');

        if ($tenantSlug) {
            // Show stats for specific tenant
            $tenant = Tenant::where('slug', $tenantSlug)->first();
            if (!$tenant) {
                $this->error("Tenant with slug '{$tenantSlug}' not found.");
                return 1;
            }

            $this->showTenantStats($tenant);
        } else {
            // Show overview for all tenants
            $this->showAllTenantsStats();
        }

        return 0;
    }

    /**
     * Show cache stats for a specific tenant
     */
    private function showTenantStats(Tenant $tenant): void
    {
        $this->info("Cache Statistics for Tenant: {$tenant->name} ({$tenant->slug})");
        $this->line("Tenant ID: {$tenant->id}");
        $this->newLine();

        $prefix = "tenant_{$tenant->id}:";

        // Check known cache keys
        $cacheKeys = [
            'notification_counts' => 'Notification counts (orders_new, reservations_pending)',
            'brand_settings' => 'Brand settings',
            'products' => 'Products list',
            'categories' => 'Categories list',
            'dining_tables' => 'Dining tables list',
        ];

        $this->table(
            ['Cache Key', 'Status', 'Description'],
            collect($cacheKeys)->map(function ($description, $key) use ($prefix) {
                $fullKey = $prefix . $key;
                $exists = Cache::has($fullKey);
                return [
                    $key,
                    $exists ? '<info>Cached</info>' : '<comment>Not cached</comment>',
                    $description
                ];
            })->toArray()
        );

        // Show cache hit/miss info if using database cache
        $this->newLine();
        $this->line("Cache Store: " . config('cache.default'));
        $this->line("Cache TTL for counts: 30 seconds");
        $this->line("Cache TTL for settings: Forever (cleared on update)");
    }

    /**
     * Show overview stats for all tenants
     */
    private function showAllTenantsStats(): void
    {
        $tenants = Tenant::all();

        $this->info("Tenant Cache Overview");
        $this->line("Total tenants: {$tenants->count()}");
        $this->newLine();

        $stats = $tenants->map(function ($tenant) {
            $prefix = "tenant_{$tenant->id}:";
            $cachedKeys = 0;
            $totalKeys = 5; // notification_counts, brand_settings, products, categories, dining_tables

            $cacheKeys = ['notification_counts', 'brand_settings', 'products', 'categories', 'dining_tables'];
            foreach ($cacheKeys as $key) {
                if (Cache::has($prefix . $key)) {
                    $cachedKeys++;
                }
            }

            return [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'cached_keys' => $cachedKeys,
                'total_keys' => $totalKeys,
                'cache_ratio' => $totalKeys > 0 ? round(($cachedKeys / $totalKeys) * 100, 1) . '%' : '0%',
            ];
        });

        $this->table(
            ['Tenant Name', 'Slug', 'Cached Keys', 'Total Keys', 'Cache Ratio'],
            $stats->map(function ($stat) {
                return [
                    $stat['name'],
                    $stat['slug'],
                    $stat['cached_keys'] . '/' . $stat['total_keys'],
                    $stat['cache_ratio'],
                ];
            })->toArray()
        );

        $this->newLine();
        $this->line("Performance Benefits:");
        $this->line("• Notification counts cached for 30 seconds (reduces DB queries)");
        $this->line("• Brand settings cached forever (cleared on update)");
        $this->line("• Cache automatically cleared when models change");
        $this->line("• Each tenant has isolated cache (no cross-tenant pollution)");
    }
}
