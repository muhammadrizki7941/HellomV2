<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupOrphanedTenantData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup-orphaned-tenant-data {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up data that belongs to non-existent tenants';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? 'DRY RUN: Simulating cleanup...' : 'Starting cleanup...');

        $tenantIds = DB::table('tenants')->pluck('id')->toArray();

        $tables = [
            'categories',
            'products',
            'dining_tables',
            'orders',
            'brand_settings',
            'payment_settings',
            'loyalty_settings',
            'point_transactions',
            'member_promotions',
            'site_promotions',
            'reservation_spaces',
            'reservation_space_images',
            'reservation_space_items',
            'reservations',
        ];

        $totalDeleted = 0;

        foreach ($tables as $table) {
            $count = DB::table($table)->whereNotIn('tenant_id', $tenantIds)->count();
            if ($count > 0) {
                $this->warn("Found {$count} orphaned records in {$table}");
                if (!$dryRun) {
                    DB::table($table)->whereNotIn('tenant_id', $tenantIds)->delete();
                    $this->info("Deleted {$count} records from {$table}");
                }
                $totalDeleted += $count;
            }
        }

        // Also check for other tables without tenant_id but related
        $relatedTables = [
            'package_items' => 'package_product_id', // relates to products
            'product_options' => 'product_id',
            'product_option_values' => 'product_option_id',
            'order_items' => 'order_id',
            'order_item_options' => 'order_item_id',
        ];

        foreach ($relatedTables as $table => $foreignKey) {
            // This is more complex, skip for now or implement later
        }

        $this->info($dryRun ? "DRY RUN complete. Would delete {$totalDeleted} records." : "Cleanup complete. Deleted {$totalDeleted} orphaned records.");
    }
}
