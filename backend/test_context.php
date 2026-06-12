<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $tenant = \App\Models\Tenant::where('slug', 'alpha')->first();
    echo 'Tenant: ' . json_encode($tenant->toArray()) . PHP_EOL;

    $ctx = new \App\Services\Tenancy\TenantContext(
        id: (int) $tenant->id,
        slug: (string) $tenant->slug,
        name: (string) $tenant->name,
        plan: (string) ($tenant->plan ?? 'trial'),
        status: (string) ($tenant->status ?? 'active'),
        trialStartedAt: $tenant->trial_started_at?->toDateString(),
        activeUntil: $tenant->active_until?->toDateString(),
        subdomain: $tenant->subdomain !== null ? (string) $tenant->subdomain : null,
        customDomain: $tenant->custom_domain !== null ? (string) $tenant->custom_domain : null
    );

    echo 'Context created successfully' . PHP_EOL;
    echo 'ID: ' . $ctx->id . ', Slug: ' . $ctx->slug . PHP_EOL;

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    echo 'File: ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    echo 'Trace: ' . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}