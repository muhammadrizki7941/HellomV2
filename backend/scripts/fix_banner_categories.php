<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
use App\Models\Category;

$targetCategoryName = 'Combo Deals';
$targetCat = Category::where('name', $targetCategoryName)->where('is_active', true)->first();
if (! $targetCat) {
    echo "Target category 'Combo Deals' not found or not active. Aborting.\n";
    exit(1);
}
$targetId = $targetCat->id;

$updated = [];
foreach (Product::where('show_as_banner', true)->get() as $p) {
    $activeCatIds = $p->categories()->where('is_active', true)->get()->pluck('id')->toArray();
    if (empty($activeCatIds)) {
        $p->categories()->syncWithoutDetaching([$targetId]);
        $updated[] = ['id' => $p->id, 'name' => $p->name, 'attached_to' => $targetId];
    }
}

if (empty($updated)) {
    echo "No products needed attaching.\n";
} else {
    echo "Attached products to category id {$targetId} ({$targetCategoryName}):\n";
    echo json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
