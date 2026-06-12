<?php

namespace App\Console\Commands;

use App\Models\BrandSetting;
use App\Models\LoyaltySetting;
use App\Models\MemberPromotion;
use App\Models\PaymentSetting;
use App\Models\PointTransaction;
use App\Models\Reservation;
use App\Models\ReservationSpace;
use App\Models\ReservationSpaceImage;
use App\Models\ReservationSpaceItem;
use App\Models\SitePromotion;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantBackfillSettingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:backfill-settings {tenant : Tenant slug to assign} {--dry-run : Show count without updating} {--force : Required to actually update}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill tenant_id for settings and reservation tables (sets tenant_id on NULL rows only)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $slug = strtolower(trim((string) $this->argument('tenant')));
        if ($slug === '') {
            $this->error('Tenant slug is required.');
            return 1;
        }

        $tenantId = Tenant::query()->where('slug', $slug)->value('id');
        if (!$tenantId) {
            $this->error("Tenant not found in DB for slug '{$slug}'.");
            return 1;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $tables = ['brand_settings', 'payment_settings', 'loyalty_settings', 'point_transactions', 'member_promotions', 'site_promotions', 'reservation_spaces', 'reservation_space_images', 'reservation_space_items', 'reservations'];
        $totalUpdated = 0;

        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                $this->warn("Table '{$table}' not found, skipping.");
                continue;
            }
            if (!Schema::hasColumn($table, 'tenant_id')) {
                $this->warn("Column '{$table}.tenant_id' not found, skipping.");
                continue;
            }

            $q = DB::table($table)->whereNull('tenant_id');
            $count = (int) $q->count();

            if ($count === 0) {
                $this->info("{$table}: no rows to backfill.");
                continue;
            }

            if ($isDryRun) {
                $this->info("{$table}: DRY-RUN would update {$count} row(s) set tenant_id={$tenantId}");
                continue;
            }

            if (!$force) {
                $this->error("{$table}: Refusing to update. Re-run with --force (or preview with --dry-run).");
                return 1;
            }

            $updated = (int) $q->update(['tenant_id' => $tenantId]);
            $this->info("{$table}: updated {$updated} row(s) set tenant_id={$tenantId}");
            $totalUpdated += $updated;
        }

        $this->info("Total updated: {$totalUpdated} row(s) across all settings tables.");
        return 0;
    }
}
