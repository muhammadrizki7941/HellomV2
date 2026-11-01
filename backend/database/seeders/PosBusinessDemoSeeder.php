<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductSerial;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\Pos\StockMovementService;
use App\Support\Pos\BusinessProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PosBusinessDemoSeeder extends Seeder
{
    public function run(): void
    {
        $businesses = [
            'fnb' => [
                'name' => 'Warung Demo',
                'category' => 'Makanan',
                'products' => [
                    ['name' => 'Nasi Goreng Demo', 'sku' => 'DEMO-FNB-001', 'price' => 25000, 'cost_price' => 12000],
                ],
            ],
            'retail' => [
                'name' => 'Toko Retail Demo',
                'category' => 'Kebutuhan Harian',
                'products' => [
                    ['name' => 'Botol Minum Demo', 'sku' => 'DEMO-RTL-001', 'barcode' => '899100000001', 'price' => 45000, 'cost_price' => 25000, 'stock' => 10],
                ],
            ],
            'konter_hp' => [
                'name' => 'Konter HP Demo',
                'category' => 'Ponsel',
                'products' => [
                    ['name' => 'Ponsel Demo 128 GB', 'sku' => 'DEMO-HP-001', 'barcode' => '899100000002', 'price' => 2500000, 'cost_price' => 2100000, 'stock' => 3, 'track_serial' => true, 'warranty_days' => 365],
                ],
            ],
            'sparepart' => [
                'name' => 'Toko Sparepart Demo',
                'category' => 'Sparepart',
                'products' => [
                    ['name' => 'Kampas Rem Demo', 'sku' => 'DEMO-SP-001', 'barcode' => '899100000003', 'price' => 85000, 'cost_price' => 50000, 'stock' => 12],
                ],
            ],
        ];

        foreach ($businesses as $type => $business) {
            $slug = 'demo-pos-' . $type;
            $organization = Organization::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $business['name'],
                    'status' => 'active',
                    'pos_tenant_slug' => $slug,
                    'business_type' => $type,
                    'enabled_modules' => BusinessProfile::preset($type),
                ]
            );
            $outlet = Outlet::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'slug' => 'utama'],
                [
                    'name' => 'Outlet Utama',
                    'tenant_slug' => $slug,
                    'is_primary' => true,
                    'is_active' => true,
                    'sort_order' => 1,
                ]
            );
            $categorySlug = 'demo-' . $type . '-kategori';
            $category = Category::withoutGlobalScope('tenant')->updateOrCreate(
                ['slug' => $categorySlug],
                [
                    'tenant_id' => $slug,
                    'outlet_id' => $outlet->id,
                    'name' => $business['category'],
                    'is_active' => true,
                    'sort_order' => 1,
                ]
            );

            foreach ($business['products'] as $index => $attributes) {
                $productSlug = 'demo-' . $type . '-produk-' . ($index + 1);
                $product = Product::withoutGlobalScope('tenant')->firstOrNew([
                    'tenant_id' => $slug,
                    'slug' => $productSlug,
                ]);
                $isNew = !$product->exists;
                $product->fill([
                    'outlet_id' => $outlet->id,
                    'category_id' => $category->id,
                    'name' => $attributes['name'],
                    'price' => $attributes['price'],
                    'cost_price' => $attributes['cost_price'],
                    'sku' => $attributes['sku'],
                    'barcode' => $attributes['barcode'] ?? null,
                    'unit' => 'pcs',
                    'is_available' => true,
                    'track_stock' => isset($attributes['stock']) || !empty($attributes['track_serial']),
                    'has_variants' => $type === 'retail',
                    'track_serial' => (bool) ($attributes['track_serial'] ?? false),
                    'warranty_days' => $attributes['warranty_days'] ?? null,
                    'min_stock' => 2,
                ]);
                if ($isNew) {
                    $product->stock = 0;
                }
                $product->save();

                if ($product->track_stock && !StockMovement::query()
                    ->where('tenant_id', $slug)
                    ->where('product_id', $product->id)
                    ->whereNull('product_variant_id')
                    ->exists()) {
                    app(StockMovementService::class)->setOnHand(
                        $product,
                        (int) ($attributes['stock'] ?? 0),
                        $outlet->id,
                        null,
                        'Saldo awal data demo'
                    );
                }

                if ($type === 'retail') {
                    $variant = ProductVariant::query()->firstOrNew([
                        'tenant_id' => $slug,
                        'sku' => 'DEMO-RTL-001-HIJAU',
                    ]);
                    $variantIsNew = !$variant->exists;
                    $variant->fill([
                        'product_id' => $product->id,
                        'name' => 'Hijau',
                        'attributes' => ['warna' => 'Hijau'],
                        'barcode' => '899100000004',
                        'price' => 45000,
                        'track_stock' => true,
                    ]);
                    if ($variantIsNew) {
                        $variant->stock = 0;
                    }
                    $variant->save();
                    if (!StockMovement::query()
                        ->where('tenant_id', $slug)
                        ->where('product_id', $product->id)
                        ->where('product_variant_id', $variant->id)
                        ->exists()) {
                        app(StockMovementService::class)->setOnHand($product, 6, $outlet->id, null, 'Saldo awal varian demo', $variant);
                    }

                    foreach ([
                        ['label' => 'Eceran', 'min_quantity' => 1, 'customer_type' => null, 'price' => 45000],
                        ['label' => 'Grosir', 'min_quantity' => 6, 'customer_type' => 'grosir', 'price' => 40000],
                    ] as $tier) {
                        ProductPriceTier::query()->updateOrCreate(
                            ['product_id' => $product->id, 'label' => $tier['label'], 'product_variant_id' => null],
                            ['tenant_id' => $slug] + $tier
                        );
                    }
                }

                if ($type === 'konter_hp') {
                    foreach (['DEMO-IMEI-0001', 'DEMO-IMEI-0002', 'DEMO-IMEI-0003'] as $serial) {
                        ProductSerial::query()->firstOrCreate(
                            ['tenant_id' => $slug, 'serial_number' => $serial],
                            [
                                'outlet_id' => $outlet->id,
                                'product_id' => $product->id,
                                'product_variant_id' => null,
                                'status' => 'available',
                            ]
                        );
                    }
                }
            }

            if ($type === 'sparepart') {
                DB::table('pos_suppliers')->updateOrInsert(
                    ['tenant_id' => $slug, 'name' => 'Supplier Demo'],
                    [
                        'outlet_id' => $outlet->id,
                        'phone' => '081234567890',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        $this->command?->info('Data demo POS F&B, retail, konter HP, dan sparepart berhasil disiapkan.');
    }
}
