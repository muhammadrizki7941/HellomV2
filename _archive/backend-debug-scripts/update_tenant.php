<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tenant = \App\Models\Tenant::where('slug', 'alpha')->first();
if ($tenant) {
    $tenant->status = 'active';
    $tenant->trial_started_at = '2026-01-20';
    $tenant->save();
    echo "Updated tenant alpha to active\n";
} else {
    echo "Tenant alpha not found\n";
}