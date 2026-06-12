<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use App\Services\Tenancy\TenantResolver;

try {
    // Simulate the request
    $request = Request::create('http://localhost:8000/t/alpha/admin/cashier/orders', 'GET');

    echo 'Path: ' . $request->getPathInfo() . PHP_EOL;

    $resolver = app(TenantResolver::class);

    // Test resolveFromRequest (should not find tenant since no route)
    $context = $resolver->resolveFromRequest($request);
    echo 'Context from resolveFromRequest: ' . ($context ? 'Found' : 'Not found') . PHP_EOL;

    // Test the fallback path parsing
    $path = $request->getPathInfo();
    if (preg_match('#^/t/([^/]+)/#', $path, $matches)) {
        $slug = $matches[1];
        echo 'Parsed slug from path: ' . $slug . PHP_EOL;
        $context = $resolver->resolveFromSlug($slug);
        echo 'Context from resolveFromSlug: ' . ($context ? 'Found' : 'Not found') . PHP_EOL;
        if ($context) {
            echo 'ID: ' . $context->id . ', Slug: ' . $context->slug . ', Name: ' . $context->name . PHP_EOL;
        }
    }

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    echo 'File: ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    echo 'Trace: ' . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}