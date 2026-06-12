<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;
use App\Models\DiningTable;
use App\Models\BrandSetting;
use App\Services\Tenancy\TenantContext;

// Set tenant context for alpha
$tenant = Tenant::where('slug', 'alpha')->first();
if (!$tenant) {
    echo "Tenant alpha not found\n";
    exit(1);
}

$context = new TenantContext(
    id: $tenant->id,
    slug: $tenant->slug,
    name: $tenant->name,
    plan: $tenant->plan,
    status: $tenant->status,
    trialStartedAt: $tenant->trial_started_at?->format('Y-m-d'),
    activeUntil: $tenant->active_until?->format('Y-m-d'),
    subdomain: $tenant->subdomain,
    customDomain: $tenant->custom_domain,
);

$app->instance(TenantContext::class, $context);

echo "Tenant: {$tenant->slug} (ID: {$tenant->id})\n";
echo "Status: {$tenant->status}\n";
echo "Trial started: {$tenant->trial_started_at}\n";

$brand = BrandSetting::current();
echo "Brand demo enabled: " . ($brand ? ($brand->customer_demo_mode_enabled ? 'true' : 'false') : 'null') . "\n";

$diningTablesCount = DiningTable::count();
echo "DiningTables count: {$diningTablesCount}\n";

if ($diningTablesCount > 0) {
    $tables = DiningTable::where('is_active', true)->orderBy('code')->limit(5)->get();
    echo "Active tables:\n";
    foreach ($tables as $table) {
        echo "  - {$table->code} (ID: {$table->id})\n";
    }
}