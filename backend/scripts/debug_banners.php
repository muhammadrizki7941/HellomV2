<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;

$rows = [];
// Active categories
$catsOut = [];
foreach (App\Models\Category::where('is_active', true)->get() as $c) {
    $catsOut[] = ['id' => $c->id, 'name' => $c->name];
}
echo "ACTIVE_CATEGORIES:\n" . json_encode($catsOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL . PHP_EOL;
foreach (Product::where('show_as_banner', true)->get() as $p) {
    $cats = $p->categories()->where('is_active', true)->get()->pluck('id')->toArray();
    $rows[] = [
        'id' => $p->id,
        'name' => $p->name,
        'is_package' => (bool) $p->is_package,
        'is_available' => (bool) $p->is_available,
        'has_image' => (bool) $p->image_path,
        'active_category_ids' => $cats,
        'banner_starts_at' => $p->banner_starts_at?->toDateTimeString(),
        'banner_ends_at' => $p->banner_ends_at?->toDateTimeString(),
    ];
}

echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
