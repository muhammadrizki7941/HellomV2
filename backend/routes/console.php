<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use App\Models\Tenant;
use App\Models\Order;
use Carbon\CarbonImmutable;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:setup', function () {
    $connection = (string) config('database.default');

    if ($connection === 'mysql') {
        $cfg = Config::get('database.connections.mysql');

        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (int) ($cfg['port'] ?? 3306);
        $database = (string) ($cfg['database'] ?? '');
        $username = (string) ($cfg['username'] ?? 'root');
        $password = (string) ($cfg['password'] ?? '');
        $charset = (string) ($cfg['charset'] ?? 'utf8mb4');
        $collation = (string) ($cfg['collation'] ?? 'utf8mb4_unicode_ci');

        if ($database === '') {
            $this->error('DB_DATABASE is empty.');
            return;
        }

        $this->info("Ensuring database exists: {$database}");

        try {
            $pdo = new \PDO(
                "mysql:host={$host};port={$port};charset={$charset}",
                $username,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            $safeDb = str_replace('`', '``', $database);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET {$charset} COLLATE {$collation}");
        } catch (\Throwable $e) {
            $this->error('Failed creating database: '.$e->getMessage());
            return;
        }
    } else {
        $this->warn("DB_CONNECTION is '{$connection}', skipping DB create.");
    }

    $this->info('Running migrations + seed...');
    $this->call('migrate:fresh', ['--seed' => true]);

    $this->info('Creating storage symlink (if needed)...');
    try {
        $this->call('storage:link');
    } catch (\Throwable $e) {
        // ignore
    }

    $this->info('Done. Admin login: admin@example.com / password');
})->purpose('Create DB, migrate, seed, and prepare local assets');

Artisan::command('tenants:seed-demo {--truncate : Truncate tenants table before seeding}', function () {
    if (!Schema::hasTable('tenants')) {
        $this->error("Table 'tenants' not found. Run: php artisan migrate");
        return 1;
    }

    $tenants = (array) config('tenancy.tenants', []);
    if ($tenants === []) {
        $this->warn('No tenants found in config(tenancy.tenants).');
        return 0;
    }

    if ($this->option('truncate')) {
        Tenant::query()->truncate();
        $this->info('Truncated tenants table.');
    }

    $count = 0;
    foreach ($tenants as $slug => $row) {
        if (!is_string($slug) || trim($slug) === '' || !is_array($row)) {
            continue;
        }

        $slug = strtolower(trim($slug));
        Tenant::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => (string) ($row['name'] ?? $slug),
                'plan' => (string) ($row['plan'] ?? 'trial'),
                'status' => (string) ($row['status'] ?? 'active'),
                'trial_started_at' => $row['trial_started_at'] ?? null,
                'active_until' => $row['active_until'] ?? null,
                'subdomain' => $row['subdomain'] ?? $slug,
                'custom_domain' => $row['custom_domain'] ?? null,
            ]
        );

        $count++;
    }

    $this->info("Seeded/updated {$count} tenant(s).");
    return 0;
})->purpose('Seed demo tenants from config/tenancy.php into the database');

Artisan::command('tenants:sync-expiry {--dry-run : Show what would change without saving}', function () {
    if (!Schema::hasTable('tenants')) {
        $this->error("Table 'tenants' not found. Run: php artisan migrate");
        return 1;
    }

    $isDryRun = (bool) $this->option('dry-run');
    $now = CarbonImmutable::now();

    $checked = 0;
    $updated = 0;

    /** @var Tenant $tenant */
    foreach (Tenant::query()->orderBy('id')->cursor() as $tenant) {
        $checked++;

        $plan = strtolower((string) ($tenant->plan ?? 'trial'));
        $status = strtolower((string) ($tenant->status ?? 'active'));

        if (in_array($status, ['expired', 'suspended', 'inactive'], true)) {
            continue;
        }

        $shouldExpire = false;

        if ($plan === 'trial' && $tenant->trial_started_at) {
            $start = CarbonImmutable::parse($tenant->trial_started_at)->startOfDay();
            $expiresAt = $start->addDays(3)->endOfDay();
            $shouldExpire = $now->greaterThan($expiresAt);
        }

        if (!$shouldExpire && in_array($plan, ['basic', 'pro'], true) && $tenant->active_until) {
            $until = CarbonImmutable::parse($tenant->active_until)->endOfDay();
            $shouldExpire = $now->greaterThan($until);
        }

        if (!$shouldExpire) {
            continue;
        }

        $updated++;

        $label = "[{$tenant->id}] {$tenant->slug} ({$tenant->plan})";
        if ($isDryRun) {
            $this->line("DRY-RUN: would set status=expired for {$label}");
            continue;
        }

        $tenant->status = 'expired';
        $tenant->save();
        $this->info("Updated: set status=expired for {$label}");
    }

    $this->info("Done. Checked {$checked} tenant(s), updated {$updated}.");
    return 0;
})->purpose('Sync tenant expiry status for DB tenants (trial: 3 days, basic/pro: active_until)');

Artisan::command('orders:backfill-tenant {tenant : Tenant slug to assign} {--dry-run : Show count without updating}', function () {
    if (!Schema::hasTable('tenants')) {
        $this->error("Table 'tenants' not found. Run: php artisan migrate");
        return 1;
    }
    if (!Schema::hasTable('orders')) {
        $this->error("Table 'orders' not found. Run: php artisan migrate");
        return 1;
    }
    if (!Schema::hasColumn('orders', 'tenant_id')) {
        $this->error("Column 'orders.tenant_id' not found. Run latest migrations.");
        return 1;
    }

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

    $q = Order::query()->withoutGlobalScopes()->whereNull('tenant_id');
    $count = (int) $q->count();

    if ((bool) $this->option('dry-run')) {
        $this->info("DRY-RUN: would update {$count} order(s) set tenant_id={$tenantId} (tenant={$slug})");
        return 0;
    }

    $updated = (int) $q->update(['tenant_id' => $tenantId]);
    $this->info("Updated {$updated} order(s): set tenant_id={$tenantId} (tenant={$slug}).");
    return 0;
})->purpose('Backfill orders.tenant_id for legacy rows (sets tenant_id on NULL rows only)');

Artisan::command('orders:purge-legacy {--dry-run : Show what would be deleted without deleting} {--force : Required to actually delete}', function () {
    if (!Schema::hasTable('orders')) {
        $this->error("Table 'orders' not found. Run: php artisan migrate");
        return 1;
    }
    if (!Schema::hasColumn('orders', 'tenant_id')) {
        $this->error("Column 'orders.tenant_id' not found. Run latest migrations.");
        return 1;
    }

    $isDryRun = (bool) $this->option('dry-run');
    $force = (bool) $this->option('force');

    $q = DB::table('orders')->whereNull('tenant_id');
    $count = (int) $q->count();

    if ($count === 0) {
        $this->info('No legacy orders found (tenant_id is already set for all rows).');
        return 0;
    }

    if ($isDryRun) {
        $this->info("DRY-RUN: would DELETE {$count} order(s) where orders.tenant_id IS NULL.");
        $this->line('Note: related order_items and order_item_options should be deleted via FK cascade (if enabled).');
        return 0;
    }

    if (!$force) {
        $this->error('Refusing to delete. Re-run with --force (or preview with --dry-run).');
        return 1;
    }

    $deleted = 0;
    DB::transaction(function () use ($q, &$deleted) {
        $deleted = (int) $q->delete();
    });

    $this->info("Deleted {$deleted} legacy order(s) where tenant_id was NULL.");
    $this->line('If MySQL foreign keys are enabled, related order_items and order_item_options are removed automatically.');
    return 0;
})->purpose('Safely purge legacy orders with NULL tenant_id (useful when data is mixed and unsafe to backfill)');

Artisan::command('tenant:backfill-core {tenant : Tenant slug to assign} {--dry-run : Show count without updating} {--force : Required to actually update}', function () {
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

    $tables = ['categories', 'products', 'dining_tables'];
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

    $this->info("Total updated: {$totalUpdated} row(s) across all core tables.");
    return 0;
})->purpose('Backfill tenant_id for categories, products, dining_tables (sets tenant_id on NULL rows only)');

Artisan::command('tenant:backfill-settings {tenant : Tenant slug to assign} {--dry-run : Show count without updating} {--force : Required to actually update}', function () {
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
})->purpose('Backfill tenant_id for settings and reservation tables (sets tenant_id on NULL rows only)');

Schedule::command('notifications:check-expiry')
    ->daily()
    ->withoutOverlapping();

Schedule::command('hellom:billing:auto-renew-wallet --limit=500')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('hellom:wallet:release-pending-settlements --limit=300')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
