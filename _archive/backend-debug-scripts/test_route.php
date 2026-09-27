<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

try {
    // Load routes
    require_once __DIR__ . '/routes/tenant_admin.php';

    // Create a test request
    $request = Request::create('http://localhost/t/alpha/admin/cashier/orders', 'GET');

    // Find the route
    $routes = Route::getRoutes();
    $route = null;
    foreach ($routes as $r) {
        if ($r->getName() === 'admin.cashier.orders') {
            $route = $r;
            break;
        }
    }

    if ($route) {
        echo 'Route found: ' . $route->getName() . PHP_EOL;
        echo 'URI: ' . $route->uri() . PHP_EOL;
        echo 'Methods: ' . implode(', ', $route->methods()) . PHP_EOL;

        // Try to match the route
        $parameters = $route->match($request)->parameters();
        echo 'Parameters: ' . json_encode($parameters) . PHP_EOL;
    } else {
        echo 'Route not found' . PHP_EOL;
    }

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    echo 'File: ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    echo 'Trace: ' . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}