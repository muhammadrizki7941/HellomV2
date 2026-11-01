<?php

namespace Tests\Pos;

use App\Models\ApiToken;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\PosStaff;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

class PosSyncTest extends PosTestCase
{
    use DatabaseTransactions;

    private function cashierToken(Organization $organization, Outlet $outlet): array
    {
        $user = User::query()->create([
            'name' => 'Cashier ' . Str::random(5),
            'email' => Str::lower(Str::random(10)) . '@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'cashier',
            'current_organization_id' => $organization->id,
        ]);
        $organization->users()->attach($user->id, ['role' => 'cashier']);
        PosStaff::query()->create([
            'organization_id' => $organization->id,
            'outlet_id' => $outlet->id,
            'tenant_id' => $outlet->tenant_slug,
            'linked_user_id' => $user->id,
            'name' => $user->name,
            'role' => 'cashier',
            'employment_status' => 'active',
        ]);
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        Entitlement::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'app_id' => $app->id],
            ['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]
        );
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 'sync test', 'token_hash' => hash('sha256', $plain)]);

        return [
            'user' => $user,
            'token' => $plain,
            'headers' => ['Authorization' => "Bearer {$plain}", 'X-Outlet-Id' => (string) $outlet->id],
        ];
    }

    private function offlineOrder(string $uuid, int $outletId, int $productId, int $quantity = 1): array
    {
        return [
            'client_uuid' => $uuid,
            'created_at_client' => now()->subMinutes(4)->toIso8601String(),
            'outlet_id' => $outletId,
            'customer_name' => 'Pelanggan offline',
            'service_type' => 'takeaway',
            'items' => [['product_id' => $productId, 'quantity' => $quantity]],
            'payment_method' => 'cash',
            'payment_amount' => 100000,
            'paid_manually' => false,
            'offline_created' => true,
        ];
    }

    public function test_batch_sync_is_idempotent_and_reports_item_rejections(): void
    {
        ['org' => $organization, 'outlet' => $outlet] = $this->makeOrganization();
        $product = $this->makeProduct($outlet, 20000);
        $cashier = $this->cashierToken($organization, $outlet);
        $valid = $this->offlineOrder((string) Str::uuid(), (int) $outlet->id, (int) $product->id);
        $invalid = $this->offlineOrder((string) Str::uuid(), (int) $outlet->id, 999999);

        $first = $this->withHeaders($cashier['headers'])
            ->postJson('/api/v1/hellom/pos/sync/orders', ['orders' => [$valid, $invalid]])
            ->assertOk()
            ->json('data.results');

        $this->assertSame('ok', $first[0]['status']);
        $this->assertSame('duplicate', $this->withHeaders($cashier['headers'])
            ->postJson('/api/v1/hellom/pos/sync/orders', ['orders' => [$valid]])
            ->assertOk()
            ->json('data.results.0.status'));
        $this->assertSame('rejected', $first[1]['status']);
        $this->assertDatabaseCount('orders', 1);
        $storedClientTime = Order::query()->where('client_uuid', $valid['client_uuid'])->value('created_at_client');
        $this->assertNotNull($storedClientTime);
        $this->assertSame(
            strtotime($valid['created_at_client']),
            strtotime((string) $storedClientTime)
        );
    }

    public function test_sync_accepts_negative_stock_and_marks_it_for_review(): void
    {
        ['org' => $organization, 'outlet' => $outlet] = $this->makeOrganization();
        $product = $this->makeProduct($outlet, 20000, ['track_stock' => true, 'stock' => 0]);
        $cashier = $this->cashierToken($organization, $outlet);
        $payload = $this->offlineOrder((string) Str::uuid(), (int) $outlet->id, (int) $product->id, 2);

        $result = $this->withHeaders($cashier['headers'])
            ->postJson('/api/v1/hellom/pos/sync/orders', ['orders' => [$payload]])
            ->assertOk()
            ->json('data.results.0');

        $this->assertSame('ok', $result['status'], json_encode($result));
        $this->assertSame('NEGATIVE_STOCK_REVIEW', $result['warnings'][0]['code']);
        $this->assertSame(-2, $result['warnings'][0]['on_hand']);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $outlet->tenant_slug,
            'outlet_id' => $outlet->id,
            'movement_type' => 'sale',
            'qty' => -2,
        ]);
        $this->assertTrue((bool) ((array) Order::query()->first()->payment_meta)['offline_created']);
    }

    public function test_sync_rejects_an_unassigned_outlet_without_creating_an_order(): void
    {
        ['org' => $organization, 'outlet' => $outlet] = $this->makeOrganization();
        $otherOutlet = $this->makeOutlet($organization);
        $product = $this->makeProduct($otherOutlet, 20000);
        $cashier = $this->cashierToken($organization, $outlet);
        $payload = $this->offlineOrder((string) Str::uuid(), (int) $otherOutlet->id, (int) $product->id);

        $this->withHeaders($cashier['headers'])
            ->postJson('/api/v1/hellom/pos/sync/orders', ['orders' => [$payload]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'POS_OUTLET_NOT_ASSIGNED');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_sync_stops_for_an_inactive_cashier_and_serials_cannot_be_sold_twice(): void
    {
        ['org' => $organization, 'outlet' => $outlet] = $this->makeOrganization();
        $organization->forceFill(['business_type' => 'konter_hp', 'enabled_modules' => ['serial_imei']])->save();
        $product = $this->makeProduct($outlet, 20000, ['track_serial' => true]);
        $serial = ProductSerial::query()->create([
            'tenant_id' => $outlet->tenant_slug,
            'outlet_id' => $outlet->id,
            'product_id' => $product->id,
            'serial_number' => 'IMEI-' . Str::upper(Str::random(10)),
            'status' => 'available',
        ]);
        $cashier = $this->cashierToken($organization, $outlet);
        $first = $this->offlineOrder((string) Str::uuid(), (int) $outlet->id, (int) $product->id);
        $first['items'][0]['serial_numbers'] = [$serial->serial_number];
        $second = $this->offlineOrder((string) Str::uuid(), (int) $outlet->id, (int) $product->id);
        $second['items'][0]['serial_numbers'] = [$serial->serial_number];

        $this->withHeaders($cashier['headers'])
            ->postJson('/api/v1/hellom/pos/sync/orders', ['orders' => [$first, $second]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'ok')
            ->assertJsonPath('data.results.1.status', 'rejected')
            ->assertJsonPath('data.results.1.code', 'SERIAL_UNAVAILABLE');

        PosStaff::query()->where('linked_user_id', $cashier['user']->id)->update(['employment_status' => 'inactive']);
        $this->withHeaders($cashier['headers'])
            ->postJson('/api/v1/hellom/pos/sync/orders', ['orders' => [$this->offlineOrder((string) Str::uuid(), (int) $outlet->id, (int) $product->id)]])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'POS_ACCOUNT_INACTIVE');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_pull_is_scoped_to_the_current_assigned_outlet(): void
    {
        ['org' => $organization, 'outlet' => $outlet] = $this->makeOrganization();
        $product = $this->makeProduct($outlet, 20000, ['cost_price' => 12000]);
        $cashier = $this->cashierToken($organization, $outlet);

        $this->withHeaders($cashier['headers'])
            ->getJson('/api/v1/hellom/pos/sync/pull')
            ->assertOk()
            ->assertJsonPath('data.outlet_id', $outlet->id)
            ->assertJsonPath('data.products.0.id', $product->id)
            ->assertJsonMissingPath('data.products.0.cost_price');

        $since = now()->subMinute()->toIso8601String();
        $product->delete();
        $this->withHeaders($cashier['headers'])
            ->getJson('/api/v1/hellom/pos/sync/pull?since=' . urlencode($since))
            ->assertOk()
            ->assertJsonPath('data.removed.products.0', $product->id);
    }
}
