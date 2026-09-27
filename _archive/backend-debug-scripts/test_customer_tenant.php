<?php

// Test customer tenant context
require_once __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Tenancy\TenantContext;
use App\Http\Middleware\SetCustomerTenant;

// Simulate a request to /order
$request = Illuminate\Http\Request::create('/order', 'GET');

// Apply our middleware
$middleware = new SetCustomerTenant();
$middleware->handle($request, function($req) {
    // Check if tenant context was set
    $tenant = $req->attributes->get('tenant');
    echo "Tenant context set: " . ($tenant ? 'YES' : 'NO') . "\n";
    if ($tenant) {
        echo "Tenant ID: " . $tenant->id . "\n";
        echo "Tenant Slug: " . $tenant->slug . "\n";
        echo "Tenant Name: " . $tenant->name . "\n";
    }

    // Check if tenant is bound in container
    $containerTenant = app()->bound(TenantContext::class) ? app()->make(TenantContext::class) : null;
    echo "Container tenant set: " . ($containerTenant ? 'YES' : 'NO') . "\n";

    return response('OK');
});

echo "Test completed\n";