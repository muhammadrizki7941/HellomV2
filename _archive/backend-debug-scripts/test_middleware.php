<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use App\Http\Middleware\Tenancy\ResolveTenant;

try {
    // Create a test request
    $request = Request::create('http://localhost/t/alpha/admin/cashier/orders', 'GET');

    echo 'Request path: ' . $request->getPathInfo() . PHP_EOL;

    // Create middleware instance
    $middleware = app(ResolveTenant::class);

    // Create a simple closure to test the middleware
    $next = function ($req) {
        echo 'Middleware passed. Tenant in attributes: ' . ($req->attributes->get('tenant') ? 'Yes' : 'No') . PHP_EOL;
        $tenant = $req->attributes->get('tenant');
        if ($tenant) {
            echo 'Tenant ID: ' . $tenant->id . ', Slug: ' . $tenant->slug . PHP_EOL;
        }
        return response('OK');
    };

    // Call the middleware
    $response = $middleware->handle($request, $next);

    echo 'Response status: ' . $response->getStatusCode() . PHP_EOL;

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    echo 'File: ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    echo 'Trace: ' . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}