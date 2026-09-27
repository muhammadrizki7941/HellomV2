<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$order = \App\Models\Order::withoutGlobalScopes()->where('order_number', 'TEST-ORD-001')->first();
if ($order) {
    echo 'Order tenant_id: ' . $order->tenant_id . PHP_EOL;
} else {
    echo 'Order not found' . PHP_EOL;
}

$tenant = \App\Models\Tenant::where('slug', 'alpha')->first();
if ($tenant) {
    echo 'Alpha tenant_id: ' . $tenant->id . PHP_EOL;
} else {
    echo 'Tenant not found' . PHP_EOL;
}