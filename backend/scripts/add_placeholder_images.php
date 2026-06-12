<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Storage;
use App\Models\Product;

$storagePath = storage_path('app/public/placeholders');
if (! is_dir($storagePath)) {
    mkdir($storagePath, 0755, true);
}

$placeholderSvg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600">
  <defs>
    <linearGradient id="g" x1="0" x2="1">
      <stop offset="0" stop-color="#e2e8f0"/>
      <stop offset="1" stop-color="#cbd5e1"/>
    </linearGradient>
  </defs>
  <rect width="100%" height="100%" fill="url(#g)" />
  <g fill="#94a3b8" font-family="Arial, Helvetica, sans-serif" font-weight="700">
    <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="48">No Image</text>
  </g>
</svg>
SVG;

$filename = 'placeholder-product.png';
$fullPath = $storagePath . DIRECTORY_SEPARATOR . $filename;
// Convert SVG to PNG (if imagick available), otherwise save SVG as PNG file content (browsers may not render).
if (extension_loaded('imagick')) {
    try {
        $im = new Imagick();
        $im->setBackgroundColor(new ImagickPixel('transparent'));
        $im->readImageBlob($placeholderSvg);
        $im->setImageFormat('png24');
        $im->writeImage($fullPath);
        $im->clear();
        $im->destroy();
    } catch (Exception $e) {
        file_put_contents($fullPath, $placeholderSvg);
    }
} else {
    // Save as SVG file with .png name; Storage::url will still point to storage path and browsers will render SVG as image in many cases.
    file_put_contents($fullPath, $placeholderSvg);
}

$relativePath = 'placeholders/' . $filename;
$updated = [];
$idsToUpdate = [8,17,24];
foreach ($idsToUpdate as $id) {
    $p = Product::find($id);
    if (! $p) continue;
    $p->image_path = $relativePath;
    $p->save();
    $updated[] = ['id' => $p->id, 'name' => $p->name, 'image_path' => $p->image_path];
}

echo "Wrote placeholder to: " . $fullPath . PHP_EOL;
if (empty($updated)) {
    echo "No products updated.\n";
} else {
    echo json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
