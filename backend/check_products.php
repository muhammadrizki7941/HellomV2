<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$products = App\Models\Product::all();
foreach($products as $p) {
    $usedInPackage = DB::table('package_items')->where('item_product_id', $p->id)->exists();
    $usedInReservation = DB::table('reservation_space_items')->where('product_id', $p->id)->exists();
    if (!$usedInPackage && !$usedInReservation) {
        echo 'Product ' . $p->id . ': ' . $p->name . ' can be deleted' . PHP_EOL;
    } else {
        echo 'Product ' . $p->id . ': ' . $p->name . ' used' . PHP_EOL;
    }
}