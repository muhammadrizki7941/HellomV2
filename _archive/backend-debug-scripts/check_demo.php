<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$resolver = app(\App\Services\Tenancy\TenantResolver::class);
$context = $resolver->resolveFromSlug('alpha');
if ($context) {
    app()->instance(\App\Services\Tenancy\TenantContext::class, $context);
    $brand = \App\Models\BrandSetting::current();
    var_dump($brand->customer_demo_mode_enabled);
} else {
    echo "No context\n";
}