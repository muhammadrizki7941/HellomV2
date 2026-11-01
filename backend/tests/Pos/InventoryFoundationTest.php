<?php

namespace Tests\Pos;

use App\Models\ProductSerial;
use App\Models\ApiToken;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Services\Pos\OrderService;
use App\Services\Pos\PricingException;
use App\Services\Pos\StockMovementService;
use Database\Seeders\PosBusinessDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class InventoryFoundationTest extends PosTestCase
{
    use DatabaseTransactions;

    public function test_business_demo_seeder_is_idempotent_and_creates_tenant_examples(): void
    {
        $this->seed(PosBusinessDemoSeeder::class);
        $this->seed(PosBusinessDemoSeeder::class);

        $organization = Organization::query()->where('slug', 'demo-pos-konter_hp')->firstOrFail();
        $this->assertSame('konter_hp', $organization->business_type);
        $this->assertContains('serial_imei', $organization->enabled_modules);
        $outlet = Outlet::query()->where('organization_id', $organization->id)->firstOrFail();
        $product = Product::withoutGlobalScope('tenant')
            ->where('tenant_id', $outlet->tenant_slug)
            ->where('sku', 'DEMO-HP-001')
            ->firstOrFail();

        $this->assertSame(3, app(StockMovementService::class)->onHand($product, $outlet->id));
        $this->assertSame(3, ProductSerial::query()->where('tenant_id', $outlet->tenant_slug)->count());
        $this->assertSame(1, Organization::query()->where('slug', 'demo-pos-retail')->count());
    }

    public function test_stock_ledger_isolated_by_outlet_and_adjusted_by_movements(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization();
        $otherOutlet = $this->makeOutlet($org, false, [], (string) $org->pos_tenant_slug);
        $product = $this->makeProduct($outlet, 10000, ['track_stock' => true, 'stock' => 0]);
        $movements = app(StockMovementService::class);
        $movements->setOnHand($product, 5, $outlet->id, null, 'Saldo awal');

        $this->assertSame(5, $movements->onHand($product, $outlet->id));
        $this->assertSame(0, $movements->onHand($product, $otherOutlet->id));

        $movements->record($product, -2, 'sale', $outlet->id);
        $this->assertSame(3, $movements->onHand($product, $outlet->id));
    }

    public function test_stock_transfer_posts_paired_outlet_movements_and_is_idempotent(): void
    {
        ['org' => $org, 'outlet' => $sourceOutlet] = $this->makeOrganization();
        $destinationOutlet = $this->makeOutlet($org, false, [], (string) $org->pos_tenant_slug);
        $product = $this->makeProduct($sourceOutlet, 12000, [
            'sku' => 'TRANSFER-001',
            'track_stock' => true,
            'stock' => 0,
        ]);
        $movements = app(StockMovementService::class);
        $movements->setOnHand($product, 5, $sourceOutlet->id, null, 'Saldo awal');
        $headers = $this->ownerHeaders($org);
        $payload = [
            'destination_outlet_id' => $destinationOutlet->id,
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'qty' => 2]],
        ];

        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/hellom/pos/stock-transfers', $payload)
            ->assertCreated();
        $transferId = $response->json('data.transfer.id');

        $this->assertSame(3, $movements->onHand($product, $sourceOutlet->id));
        $this->assertSame(2, $movements->onHand($product, $destinationOutlet->id));
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'stock_transfer_out',
            'reference_id' => $transferId,
            'outlet_id' => $sourceOutlet->id,
            'qty' => -2,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'stock_transfer_in',
            'reference_id' => $transferId,
            'outlet_id' => $destinationOutlet->id,
            'qty' => 2,
        ]);

        $this->withHeaders($headers)
            ->postJson('/api/v1/hellom/pos/stock-transfers', $payload)
            ->assertCreated();
        $this->assertSame(3, $movements->onHand($product, $sourceOutlet->id));
        $this->assertSame(2, $movements->onHand($product, $destinationOutlet->id));
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('pos_stock_transfers')
            ->where('client_uuid', $payload['client_uuid'])
            ->count());
    }

    public function test_stock_transfer_rejects_insufficient_stock_and_outlets_from_other_organizations(): void
    {
        ['org' => $org, 'outlet' => $sourceOutlet] = $this->makeOrganization();
        $destinationOutlet = $this->makeOutlet($org, false, [], (string) $org->pos_tenant_slug);
        $product = $this->makeProduct($sourceOutlet, 12000, [
            'sku' => 'TRANSFER-002',
            'track_stock' => true,
            'stock' => 0,
        ]);
        $headers = $this->ownerHeaders($org);

        $this->withHeaders($headers)
            ->postJson('/api/v1/hellom/pos/stock-transfers', [
                'destination_outlet_id' => $destinationOutlet->id,
                'items' => [['product_id' => $product->id, 'qty' => 1]],
            ])
            ->assertStatus(409);

        ['outlet' => $otherOrganizationOutlet] = $this->makeOrganization('B');
        $this->withHeaders($headers)
            ->postJson('/api/v1/hellom/pos/stock-transfers', [
                'destination_outlet_id' => $otherOrganizationOutlet->id,
                'items' => [['product_id' => $product->id, 'qty' => 1]],
            ])
            ->assertNotFound();
    }

    public function test_stock_transfer_moves_serial_to_matching_destination_product(): void
    {
        ['org' => $org, 'outlet' => $sourceOutlet] = $this->makeOrganization();
        $destinationOutlet = $this->makeOutlet($org);
        $sourceProduct = $this->makeProduct($sourceOutlet, 2500000, [
            'sku' => 'SERIAL-TRANSFER-001',
            'track_stock' => true,
            'track_serial' => true,
            'stock' => 0,
        ]);
        $destinationProduct = $this->makeProduct($destinationOutlet, 2500000, [
            'sku' => 'SERIAL-TRANSFER-001',
            'track_stock' => true,
            'track_serial' => true,
            'stock' => 0,
        ]);
        app(StockMovementService::class)->setOnHand($sourceProduct, 2, $sourceOutlet->id, null, 'Saldo awal');
        $serial = ProductSerial::query()->create([
            'tenant_id' => $sourceOutlet->tenant_slug,
            'outlet_id' => $sourceOutlet->id,
            'product_id' => $sourceProduct->id,
            'serial_number' => 'IMEI-TRANSFER-' . Str::upper(Str::random(8)),
            'status' => 'available',
        ]);

        $this->withHeaders($this->ownerHeaders($org))
            ->postJson('/api/v1/hellom/pos/stock-transfers', [
                'destination_outlet_id' => $destinationOutlet->id,
                'items' => [[
                    'product_id' => $sourceProduct->id,
                    'qty' => 1,
                    'serial_numbers' => [$serial->serial_number],
                ]],
            ])
            ->assertCreated();

        $this->assertSame(1, app(StockMovementService::class)->onHand($sourceProduct, $sourceOutlet->id));
        $this->assertSame(1, app(StockMovementService::class)->onHand($destinationProduct, $destinationOutlet->id));
        $this->assertSame((string) $destinationOutlet->tenant_slug, $serial->fresh()->tenant_id);
        $this->assertSame((int) $destinationProduct->id, (int) $serial->fresh()->product_id);
        $this->assertSame((int) $destinationOutlet->id, (int) $serial->fresh()->outlet_id);
    }

    public function test_sale_requires_an_available_serial_and_records_warranty_snapshot(): void
    {
        ['outlet' => $outlet] = $this->makeOrganization();
        $product = $this->makeProduct($outlet, 2500000, [
            'track_stock' => true,
            'track_serial' => true,
            'stock' => 0,
            'cost_price' => 2000000,
            'warranty_days' => 365,
        ]);
        app(StockMovementService::class)->setOnHand($product, 2, $outlet->id, null, 'Stok awal');
        $serial = ProductSerial::query()->create([
            'tenant_id' => $outlet->tenant_slug,
            'outlet_id' => $outlet->id,
            'product_id' => $product->id,
            'serial_number' => 'IMEI-' . Str::upper(Str::random(10)),
            'status' => 'available',
        ]);
        $headers = $this->ownerHeaders($outlet->organization);
        $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/orders/preview', [
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'serial_numbers' => [$serial->serial_number],
            ]],
        ])->assertOk()
            ->assertJsonMissingPath('data.lines.0.cost_price')
            ->assertJsonMissingPath('data.lines.0.warranty_days');
        $input = [
            'source' => OrderService::SOURCE_POS,
            'client_uuid' => (string) Str::uuid(),
            'created_at_client' => now()->toIso8601String(),
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'serial_numbers' => [$serial->serial_number],
            ]],
        ];

        $order = app(OrderService::class)->create($outlet, $input);
        $item = $order->items()->firstOrFail();
        $this->assertSame($serial->serial_number, $item->serial_number);
        $this->assertSame(2000000, (int) $item->cost_price);
        $this->assertNotNull($item->warranty_expires_at);
        $this->withHeaders($headers)->getJson("/api/v1/hellom/pos/orders/{$order->id}/receipt")
            ->assertOk()
            ->assertJsonPath('data.receipt.items.0.serial_number', $serial->serial_number)
            ->assertJsonPath('data.receipt.items.0.warranty_expires_at', $item->warranty_expires_at->toDateString());
        $this->assertSame('sold', $serial->fresh()->status);
        $this->assertSame($order->id, app(OrderService::class)->create($outlet, $input)->id);

        try {
            app(OrderService::class)->create($outlet, [
                ...$input,
                'client_uuid' => (string) Str::uuid(),
            ]);
            $this->fail('IMEI yang sudah terjual seharusnya ditolak.');
        } catch (PricingException $exception) {
            $this->assertSame('SERIAL_UNAVAILABLE', $exception->errorCode);
        }

        app(OrderService::class)->cancel($order, 'Pembatalan uji');
        $this->assertSame('available', $serial->fresh()->status);
        $this->assertSame(2, app(StockMovementService::class)->onHand($product, $outlet->id));
    }

    public function test_non_fnb_refund_restores_stock_and_serial_once(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization();
        $org->forceFill([
            'business_type' => 'konter_hp',
            'enabled_modules' => ['serial_imei', 'warranty'],
        ])->save();
        $product = $this->makeProduct($outlet, 2500000, [
            'track_stock' => true,
            'track_serial' => true,
            'stock' => 0,
            'warranty_days' => 365,
        ]);
        $movements = app(StockMovementService::class);
        $movements->setOnHand($product, 2, $outlet->id, null, 'Stok awal');
        $serial = ProductSerial::query()->create([
            'tenant_id' => $outlet->tenant_slug,
            'outlet_id' => $outlet->id,
            'product_id' => $product->id,
            'serial_number' => 'IMEI-REFUND-' . Str::upper(Str::random(8)),
            'status' => 'available',
        ]);
        $orders = app(OrderService::class);
        $order = $orders->create($outlet, [
            'source' => OrderService::SOURCE_POS,
            'client_uuid' => (string) Str::uuid(),
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'serial_numbers' => [$serial->serial_number],
            ]],
        ]);
        $orders->markPaid($order, 'cash', (int) $order->final_amount);

        $orders->refund($order, 'Pelanggan mengembalikan barang');

        $this->assertSame(2, $movements->onHand($product, $outlet->id));
        $this->assertSame('available', $serial->fresh()->status);
        $this->assertNull($serial->fresh()->sold_order_id);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $outlet->tenant_slug,
            'reference_type' => 'order_refund',
            'reference_id' => $order->id,
            'product_id' => $product->id,
            'movement_type' => 'return',
            'qty' => 1,
        ]);
        $this->assertTrue((bool) $order->fresh()->payment_meta['stock_refunded']);

        try {
            $orders->refund($order, 'Refund kedua');
            $this->fail('Transaksi yang sudah direfund tidak boleh direfund lagi.');
        } catch (PricingException $exception) {
            $this->assertSame('ORDER_NOT_PAID', $exception->errorCode);
        }

        $this->assertSame(1, \App\Models\StockMovement::query()
            ->where('reference_type', 'order_refund')
            ->where('reference_id', $order->id)
            ->count());
    }

    public function test_fnb_refund_keeps_existing_no_restock_behavior(): void
    {
        ['outlet' => $outlet] = $this->makeOrganization();
        $product = $this->makeProduct($outlet, 25000, ['track_stock' => true, 'stock' => 0]);
        $movements = app(StockMovementService::class);
        $movements->setOnHand($product, 3, $outlet->id, null, 'Stok awal');
        $orders = app(OrderService::class);
        $order = $orders->create($outlet, [
            'source' => OrderService::SOURCE_POS,
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $orders->markPaid($order, 'cash', (int) $order->final_amount);

        $orders->refund($order, 'Pesanan tidak jadi');

        $this->assertSame(2, $movements->onHand($product, $outlet->id));
        $this->assertDatabaseMissing('stock_movements', [
            'reference_type' => 'order_refund',
            'reference_id' => $order->id,
        ]);
    }

    public function test_purchase_stocktake_and_sale_keep_signed_movement_history(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization();
        $org->forceFill([
            'business_type' => 'konter_hp',
            'enabled_modules' => ['serial_imei', 'warranty', 'suppliers_purchasing', 'stock_opname'],
        ])->save();
        $product = $this->makeProduct($outlet, 2500000, [
            'track_stock' => true,
            'track_serial' => true,
            'stock' => 0,
            'cost_price' => 2000000,
            'warranty_days' => 365,
        ]);
        $headers = $this->ownerHeaders($org);
        $supplierResponse = $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/suppliers', [
            'name' => 'Distributor Uji',
            'phone' => '08123456789',
        ])->assertCreated();
        $supplierId = $supplierResponse->json('data.supplier.id');

        $serials = ['IMEI-TEST-001', 'IMEI-TEST-002', 'IMEI-TEST-003'];
        $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/purchases', [
            'supplier_id' => $supplierId,
            'items' => [[
                'product_id' => $product->id,
                'qty' => 3,
                'unit_cost' => 2000000,
                'serial_numbers' => $serials,
            ]],
        ])->assertCreated();

        $movements = app(StockMovementService::class);
        $this->assertSame(3, $movements->onHand($product, $outlet->id));
        $stockProduct = $this->makeProduct($outlet, 1000, ['track_stock' => true, 'stock' => 0]);
        $movements->setOnHand($stockProduct, 5, $outlet->id, null, 'Saldo awal untuk opname');
        $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/stocktakes', [
            'notes' => 'Hitung fisik',
            'items' => [['product_id' => $stockProduct->id, 'counted_qty' => 4]],
        ])->assertCreated();
        $this->assertSame(4, $movements->onHand($stockProduct, $outlet->id));

        $order = app(OrderService::class)->create($outlet, [
            'source' => OrderService::SOURCE_POS,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'serial_numbers' => [$serials[0]]]],
        ]);
        $this->assertSame(2, $movements->onHand($product, $outlet->id));
        $this->assertSame('sold', ProductSerial::query()->where('serial_number', $serials[0])->value('status'));
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $outlet->tenant_slug,
            'product_id' => $stockProduct->id,
            'movement_type' => 'adjustment',
            'qty' => -1,
        ]);
        $this->assertSame(1, $order->items()->count());
        $this->withHeaders($headers)
            ->getJson('/api/v1/hellom/pos/warranties/search?serial_number=' . $serials[0])
            ->assertOk()
            ->assertJsonPath('data.warranties.0.serial_number', $serials[0]);
    }

    public function test_csv_import_keeps_valid_rows_and_reports_invalid_rows(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization();
        $org->forceFill(['enabled_modules' => ['barcode']])->save();
        $categoryProduct = $this->makeProduct($outlet, 1000);
        $headers = $this->ownerHeaders($org);
        $rows = ['category_id,name,sku,barcode,unit,price,cost_price,track_stock,stock,min_stock,has_variants,track_serial,warranty_days,description,is_available'];
        for ($number = 1; $number <= 200; $number++) {
            $sku = 'CSV-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            $barcode = '899000000' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
            $invalidPrice = in_array($number, [37, 101, 198], true);
            $price = $invalidPrice ? -1 : 25000;
            $trackStock = $number === 1 ? 'true' : 'false';
            $stock = $number === 1 ? 5 : 0;
            $rows[] = "{$categoryProduct->category_id},Produk Demo {$number},{$sku},{$barcode},pcs,{$price},10000,{$trackStock},{$stock},2,false,false,,Impor demo,true";
        }
        $csv = implode("\n", $rows);

        $response = $this->withHeaders($headers)->post('/api/v1/hellom/pos/products/import', [
            'file' => UploadedFile::fake()->createWithContent('produk.csv', $csv),
        ])->assertOk();

        $this->assertSame(197, $response->json('data.created_count'), json_encode($response->json()));
        $this->assertSame(3, $response->json('data.error_count'));
        $this->assertSame(5, app(StockMovementService::class)->onHand(
            Product::withoutGlobalScope('tenant')->where('sku', 'CSV-001')->firstOrFail(),
            $outlet->id
        ));
    }

    public function test_updating_product_keeps_its_tenant_unique_sku(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization();
        $product = $this->makeProduct($outlet, 15000, ['sku' => 'UNIQUE-001']);
        $headers = $this->ownerHeaders($org);

        $this->withHeaders($headers)->patchJson("/api/v1/hellom/pos/products/{$product->id}", [
            'category_id' => $product->category_id,
            'name' => $product->name,
            'price' => 15000,
            'sku' => 'UNIQUE-001',
        ])->assertOk();

        $otherProduct = $this->makeProduct($outlet, 10000, ['sku' => 'UNIQUE-002']);
        $this->withHeaders($headers)->patchJson("/api/v1/hellom/pos/products/{$otherProduct->id}", [
            'category_id' => $otherProduct->category_id,
            'name' => $otherProduct->name,
            'price' => 10000,
            'sku' => 'UNIQUE-001',
        ])->assertUnprocessable();
    }

    private function ownerHeaders(Organization $organization): array
    {
        $user = User::query()->create([
            'name' => 'Owner Inventori',
            'email' => Str::lower(Str::random(10)) . '@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'tenant_admin',
            'current_organization_id' => $organization->id,
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner']);
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        Entitlement::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'app_id' => $app->id],
            ['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]
        );
        $token = Str::random(40);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'inventory-test',
            'token_hash' => hash('sha256', $token),
        ]);

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
