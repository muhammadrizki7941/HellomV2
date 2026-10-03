<?php
// Courier-rates e2e (hellom_pos_test only): RajaOngkir switched on (the mock answers via
// RAJAONGKIR_SANDBOX_URL) and a physical product with "ongkir otomatis" for the builder seller
// (run tests/e2e/builder-seed.php first). Writes storage/app/shipping_seed.json.
// "cleanup" removes the product/orders and the shipping settings.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Organization;
use App\Services\Landing\ProductService;
use App\Services\Shipping\ShippingSettings;
use Illuminate\Support\Facades\DB;

if (config('database.connections.mysql.database') !== 'hellom_pos_test') {
    fwrite(STDERR, "Refusing: not hellom_pos_test\n");
    exit(1);
}

$org = Organization::query()->where('slug', 'e2e-builder')->first();
if ($org) {
    $orderIds = DB::table('landing_page_orders')->where('organization_id', $org->id)->pluck('id');
    DB::table('landing_order_items')->whereIn('order_id', $orderIds)->delete();
    if (Illuminate\Support\Facades\Schema::hasTable('finance_journal_entries')) { // finance branch
        DB::table('finance_journal_entries')->where('organization_id', $org->id)->delete();
    }
    DB::table('seller_balance_ledger')->where('organization_id', $org->id)->delete();
    DB::table('seller_balances')->where('organization_id', $org->id)->delete();
    DB::table('landing_page_orders')->whereIn('id', $orderIds)->delete();
    DB::table('landing_products')->where('organization_id', $org->id)->delete();
    $org->forceFill(['landing_shipping' => null])->save();
}
if (($argv[1] ?? '') === 'cleanup') {
    DB::table('system_settings')->whereIn('key', ['shipping_settings', 'shipping_rajaongkir_api_key'])->delete();
    echo "cleaned\n";
    exit(0);
}
if (!$org) {
    fwrite(STDERR, "Run tests/e2e/builder-seed.php first\n");
    exit(1);
}

app(ShippingSettings::class)->update(['provider' => 'rajaongkir', 'api_key' => 'e2e-rajaongkir-key', 'couriers' => ['jne', 'jnt', 'sicepat']]);
// Ship-from place is set by the seller in the browser test; the product needs one to be saved.
$org->forceFill(['landing_shipping' => ['origin' => ['id' => '17473', 'label' => 'GAMBIR, GAMBIR, JAKARTA PUSAT, DKI JAKARTA, 10110'], 'couriers' => ['jne', 'jnt', 'sicepat']]])->save();
$product = app(ProductService::class)->save($org->fresh(), [
    'type' => 'physical', 'name' => 'Kopi Gayo 250 g', 'price' => 200000, 'stock' => 10, 'shipping_mode' => 'courier', 'weight_grams' => 600,
]);
$org->forceFill(['landing_shipping' => null])->save();

file_put_contents(storage_path('app/shipping_seed.json'), json_encode(['product' => $product->public_id, 'product_id' => $product->id]));
echo "seeded\n";
