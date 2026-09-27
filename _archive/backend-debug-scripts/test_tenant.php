<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $resolver = app(\App\Services\Tenancy\TenantResolver::class);
    $context = $resolver->resolveFromSlug('beta');

    echo 'Context: ' . ($context ? 'Found' : 'Not found') . PHP_EOL;
    if ($context) {
        echo 'ID: ' . $context->id . ', Slug: ' . $context->slug . ', Name: ' . $context->name . PHP_EOL;

        // Set tenant context
        app()->instance(\App\Services\Tenancy\TenantContext::class, $context);

        $categories = \App\Models\Category::query()->orderBy('sort_order')->orderBy('name')->get();
        echo 'Total categories for beta: ' . $categories->count() . PHP_EOL;
        foreach ($categories as $cat) {
            echo '- ' . $cat->name . ' (ID: ' . $cat->id . ', Tenant: ' . $cat->tenant_id . ', Active: ' . ($cat->is_active ? 'Yes' : 'No') . ', Products: ' . $cat->products()->count() . ')' . PHP_EOL;
        }
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    echo 'File: ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
}