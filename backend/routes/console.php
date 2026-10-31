<?php

\Illuminate\Support\Facades\Schedule::call(function () {
    \App\Models\PlatformMarketingEvent::whereIn('delivery_status', ['pending', 'failed'])->where('attempts', '<', 5)
        ->where('created_at', '>', now()->subDays(7))->where('updated_at', '<', now()->subMinutes(5))
        ->orderBy('id')->limit(20)->pluck('id')->each(fn ($id) => app(\App\Services\PlatformMarketing::class)->deliver($id));
})->everyFiveMinutes()->name('platform-meta-retry')->withoutOverlapping();

Schedule::call(function () {
    \App\Models\CheckoutIntent::query()
        ->whereNotNull('status_token_hash')
        ->whereIn('status', ['gateway_pending', 'pending'])
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', now())
        ->orderBy('id')
        ->limit(100)
        ->get()
        ->each(function (\App\Models\CheckoutIntent $intent): void {
            \Illuminate\Support\Facades\DB::transaction(function () use ($intent): void {
                $locked = \App\Models\CheckoutIntent::query()->lockForUpdate()->find($intent->id);
                if (!$locked || !in_array((string) $locked->status, ['gateway_pending', 'pending'], true) || !$locked->expires_at?->isPast()) {
                    return;
                }
                $locked->forceFill(['status' => 'expired'])->save();
                $locked->payments()->where('status', 'pending')->update(['status' => 'expired']);
                $locked->subscription?->forceFill(['status' => 'expired'])->save();
                \App\Models\Invoice::query()
                    ->whereKey((int) data_get($locked->metadata, 'invoice_id'))
                    ->where('status', 'issued')
                    ->update(['status' => 'void']);
                \Illuminate\Support\Facades\Log::info('Expired POS checkout payment', [
                    'checkout_intent_id' => $locked->id,
                    'invoice_id' => data_get($locked->metadata, 'invoice_id'),
                ]);
            }, 3);
        });
})->everyFiveMinutes()->name('pos-checkout-expiry')->withoutOverlapping();

use Illuminate\Foundation\Inspiring;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use App\Models\Tenant;
use App\Models\Order;
use Carbon\CarbonImmutable;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('hellom:billing:simulate-pos-checkout {intent} {--status=paid : paid, pending, failed, or expired}', function () {
    if (!app()->environment(['local', 'testing'])
        || (string) config('payments.providers.ipaymu.environment') !== 'sandbox'
        || !config('payments.mock.enabled')) {
        $this->error('Simulasi hanya tersedia di environment local/testing dengan IPAYMU_ENV=sandbox dan BILLING_MOCK_ENABLED=true.');
        return 1;
    }

    $intent = \App\Models\CheckoutIntent::query()
        ->with('payments')
        ->where('intent_token', (string) $this->argument('intent'))
        ->first();
    $attempt = $intent?->payments()->latest('id')->first();
    if (!$intent || !$attempt || !$attempt->transaction_id) {
        $this->error('Checkout POS atau transaksi iPaymu tidak ditemukan.');
        return 1;
    }
    if ($attempt->status === 'paid' || $intent->status === 'confirmed') {
        $this->warn('Pembayaran checkout ini sudah dikonfirmasi.');
        return 0;
    }

    $statusCode = match (strtolower((string) $this->option('status'))) {
        'paid' => 1,
        'pending' => 0,
        'failed' => 5,
        'expired' => -2,
        default => null,
    };
    if ($statusCode === null) {
        $this->error('Status harus paid, pending, failed, atau expired.');
        return 1;
    }

    $config = app(\App\Services\Hellom\IpaymuSettingsService::class)->getConfig();
    if ($config['va'] === '' || $config['api_key'] === '') {
        $this->error('Kredensial iPaymu sandbox belum dikonfigurasi.');
        return 1;
    }

    Http::fake([
        '*' => Http::response([
            'Status' => 200,
            'Data' => [
                'TransactionId' => $attempt->transaction_id,
                'ReferenceId' => $attempt->reference_id,
                'Amount' => (int) $attempt->amount,
                'Status' => $statusCode,
            ],
        ], 200),
    ]);

    $payload = [
        'trx_id' => (string) $attempt->transaction_id,
        'reference_id' => (string) $attempt->reference_id,
        'status_code' => $statusCode,
        'transaction_status_code' => $statusCode,
        'paid_off' => $statusCode === 1,
        'is_escrow' => false,
        'additional_info' => [],
    ];
    $signature = app(\App\Services\Payments\IpaymuCallbackSignature::class)->sign($payload, (string) $config['va']);
    $request = \Illuminate\Http\Request::create('/api/v1/hellom/public/pos-checkout/ipaymu/callback', 'POST', $payload);
    $request->headers->set('X-Signature', $signature);
    $response = app(\App\Http\Controllers\Api\V1\Hellom\PosCheckoutController::class)
        ->notify($request, app(\App\Services\Payments\IpaymuCallbackSignature::class));
    $responseData = $response->getData(true);
    if ($response->getStatusCode() >= 400) {
        $this->error('Simulasi callback gagal: ' . (string) ($responseData['status'] ?? 'unknown'));
        return 1;
    }

    Log::info('POS iPaymu callback simulated', [
        'checkout_intent_id' => $intent->id,
        'payment_id' => $attempt->id,
        'simulated_status' => $this->option('status'),
    ]);
    $this->info('Callback sandbox simulasi selesai: ' . (string) ($responseData['status'] ?? 'ok'));
    return 0;
})->purpose('Simulate a signed iPaymu POS callback using a mocked sandbox transaction check');

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

// New sign-ups: verification reminder after 24 h, tips on day 2 (once each).
Schedule::command('users:lifecycle-emails')->hourly()->withoutOverlapping();

Schedule::command('hellom:billing:auto-renew-wallet --limit=500')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('hellom:wallet:release-pending-settlements --limit=300')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('hellom:billing:expire-subscriptions --limit=500')
    ->hourly()
    ->withoutOverlapping();

// Member points past their expiry (per-organization setting points_expire_months).
Schedule::command('pos:points expire')
    ->dailyAt('02:30')
    ->withoutOverlapping();

// Landing page sales (Fase 2): lost webhooks, order expiry, balance release, withdrawal SLA.
Schedule::command('landing:orders reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('landing:orders expire')->everyTenMinutes()->withoutOverlapping();
Schedule::command('landing:orders release')->everyTenMinutes()->withoutOverlapping();
Schedule::command('landing:orders sla')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('landing:bookings-remind')->hourly()->withoutOverlapping();
Schedule::command('balance:reconcile')->dailyAt('03:10')->withoutOverlapping();
// Email history (Pengaturan Email › Riwayat) is kept 90 days.
Schedule::command('model:prune', ['--model' => [\App\Models\EmailLog::class]])->dailyAt('03:40')->withoutOverlapping();

// Heartbeat for GET /api/health: proves cron schedule:run is installed and running.
Schedule::call(fn () => Cache::forever(HealthController::HEARTBEAT_KEY, time()))
    ->everyMinute()
    ->name('health:heartbeat');
