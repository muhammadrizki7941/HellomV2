<?php

namespace Tests\Pos;

use App\Models\ApiToken;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\PosStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

/** Owner/admin decides per cashier which POS features are allowed; "Kasir & pesanan" always is. */
class CashierPermissionsTest extends PosTestCase
{
    use DatabaseTransactions;

    private function entitle(Organization $org): void
    {
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        Entitlement::query()->firstOrCreate(['organization_id' => $org->id, 'app_id' => $app->id],
            ['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
    }

    /** @return array{0: array<string,string>, 1: User} */
    private function login(Organization $org, string $pivotRole): array
    {
        $user = User::query()->create([
            'name' => 'User ' . Str::random(4), 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('secret-password'),
            'role' => $pivotRole === 'owner' ? 'tenant_admin' : 'cashier', 'current_organization_id' => $org->id,
        ]);
        $org->users()->attach($user->id, ['role' => $pivotRole]);
        $this->entitle($org);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 'test', 'token_hash' => hash('sha256', $plain)]);

        return [['Authorization' => "Bearer {$plain}"], $user];
    }

    /** @return array{0: array<string,string>, 1: PosStaff} */
    private function cashier(Organization $org, Outlet $outlet, ?array $permissions = null): array
    {
        [$headers, $user] = $this->login($org, 'cashier');
        $staff = PosStaff::query()->create([
            'organization_id' => $org->id, 'outlet_id' => $outlet->id, 'tenant_id' => $outlet->tenant_slug, 'linked_user_id' => $user->id,
            'name' => $user->name, 'role' => 'cashier', 'employment_status' => 'active', 'permissions' => $permissions,
        ]);

        return [$headers, $staff];
    }

    public function test_default_cashier_can_sell_but_not_manage(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('P');
        $product = $this->makeProduct($outlet, 15000);
        [$h] = $this->cashier($org, $outlet);
        $api = '/api/v1/hellom/pos';

        // Always allowed: everything needed to take and pay an order.
        $this->withHeaders($h)->getJson("{$api}/products")->assertOk();
        $this->withHeaders($h)->getJson("{$api}/categories")->assertOk();
        $this->withHeaders($h)->getJson("{$api}/tables")->assertOk();
        $this->withHeaders($h)->getJson("{$api}/members/search?q=0812")->assertOk();
        $order = $this->withHeaders($h)->postJson("{$api}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated()->json('data.order');
        $this->withHeaders($h)->postJson("{$api}/orders/{$order['id']}/payment", ['payment_method' => 'cash', 'payment_amount' => 20000])->assertOk();
        $this->withHeaders($h)->getJson("{$api}/orders")->assertOk();

        // Not granted by default → 403 with a clear code.
        foreach ([
            ['POST', "{$api}/products", ['name' => 'Kopi', 'price' => 10000]],
            ['POST', "{$api}/categories", ['name' => 'Minuman']],
            ['GET', "{$api}/reports/summary"],
            ['POST', "{$api}/orders/{$order['id']}/refund", ['reason' => 'x']],
            ['GET', "{$api}/members/export"],
            ['PUT', "{$api}/loyalty/settings", []],
            ['GET', "{$api}/customer-experience/dashboard"],
            ['PUT', "{$api}/outlet-settings", []],
        ] as $call) {
            $this->withHeaders($h)->json($call[0], $call[1], $call[2] ?? [])
                ->assertForbidden()->assertJsonPath('error.code', 'POS_PERMISSION_DENIED');
        }
        // Owner/admin only, never grantable.
        foreach ([['GET', "{$api}/staff"], ['POST', "{$api}/outlets", ['name' => 'X']], ['GET', "{$api}/payment-settings"]] as $call) {
            $this->withHeaders($h)->json($call[0], $call[1], $call[2] ?? [])->assertForbidden();
        }
        $this->withHeaders($h)->getJson("{$api}/me/access")->assertOk()
            ->assertJsonPath('data.is_cashier', true)
            ->assertJsonPath('data.permissions.orders', true)
            ->assertJsonPath('data.permissions.products', false)
            ->assertJsonPath('data.permissions.members', true)
            ->assertJsonPath('data.permissions.tables', true); // owner decision: on by default
    }

    public function test_cashier_opens_and_closes_own_cash_drawer_at_any_outlet(): void
    {
        ['org' => $org] = $this->makeOrganization('S');
        $outlet = $this->makeOutlet($org); // not the primary outlet
        $product = $this->makeProduct($outlet, 30000);
        [$h, $staff] = $this->cashier($org, $outlet);
        $api = '/api/v1/hellom/pos';

        $this->withHeaders($h)->getJson("{$api}/me/cash")->assertOk()
            ->assertJsonPath('data.staff_id', $staff->id)->assertJsonPath('data.open', null);
        $this->withHeaders($h)->postJson("{$api}/staff/{$staff->id}/cash/open", ['opening_cash' => 200000])->assertCreated();
        $this->withHeaders($h)->postJson("{$api}/staff/{$staff->id}/cash/open", ['opening_cash' => 1])->assertStatus(422);

        $order = $this->withHeaders($h)->postJson("{$api}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated()->json('data.order');
        $this->withHeaders($h)->postJson("{$api}/orders/{$order['id']}/payment", ['payment_method' => 'cash', 'payment_amount' => 50000])->assertOk();

        $this->withHeaders($h)->getJson("{$api}/me/cash")->assertOk()
            ->assertJsonPath('data.open.opening_cash', 200000)
            ->assertJsonPath('data.open.live_cash_sales', (int) $order['final_amount'])
            ->assertJsonPath('data.open.live_expected_cash', 200000 + (int) $order['final_amount']);

        $closed = $this->withHeaders($h)->postJson("{$api}/staff/{$staff->id}/cash/close", ['closing_cash' => 200000 + (int) $order['final_amount'] - 5000])
            ->assertOk()->json('data.cash_log');
        $this->assertSame('closed', $closed['status']);
        $this->assertSame(-5000, $closed['difference_cash']);
        $this->withHeaders($h)->getJson("{$api}/me/cash")->assertJsonPath('data.open', null)->assertJsonPath('data.last_closed.difference_cash', -5000);

        // Switched off: no drawer for this cashier.
        $staff->forceFill(['permissions' => ['cash_control' => false]])->save();
        $this->withHeaders($h)->getJson("{$api}/me/cash")->assertForbidden();
        $this->withHeaders($h)->postJson("{$api}/staff/{$staff->id}/cash/open", ['opening_cash' => 1])->assertForbidden();
    }

    public function test_owner_grants_and_revokes_features_dynamically(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('Q');
        $product = $this->makeProduct($outlet, 15000);
        [$h, $staff] = $this->cashier($org, $outlet);
        [$owner] = $this->login($org, 'owner');
        $owner += ['X-Outlet-Id' => (string) $outlet->id];
        $api = '/api/v1/hellom/pos';

        $catalog = $this->withHeaders($owner)->getJson("{$api}/staff")->assertOk()->json('data.meta.permissions');
        $this->assertSame('orders', $catalog[0]['key']);
        $this->assertTrue($catalog[0]['locked']);

        $update = fn (array $permissions) => $this->withHeaders($owner)->putJson("{$api}/staff/{$staff->id}", [
            'name' => $staff->name, 'role' => 'cashier', 'employment_status' => 'active', 'linked_user_id' => $staff->linked_user_id, 'permissions' => $permissions,
        ])->assertOk();

        // Grant reports + products + refund; trying to switch off orders has no effect.
        $update(['reports' => true, 'products' => true, 'order_refund' => true, 'orders' => false]);
        $this->assertTrue($staff->fresh()->permissions['orders']);
        $this->withHeaders($h)->getJson("{$api}/reports/summary")->assertOk();
        $this->withHeaders($h)->postJson("{$api}/categories", ['name' => 'Minuman'])->assertSuccessful();
        $order = $this->withHeaders($h)->postJson("{$api}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated()->json('data.order');
        $this->withHeaders($h)->postJson("{$api}/orders/{$order['id']}/payment", ['payment_method' => 'cash', 'payment_amount' => 20000])->assertOk();
        $this->withHeaders($h)->postJson("{$api}/orders/{$order['id']}/refund", ['reason' => 'Salah input'])->assertOk();
        $this->withHeaders($h)->getJson("{$api}/me/access")->assertJsonPath('data.permissions.reports', true);

        // Revoke: takes effect on the next request, no re-login.
        $update(['reports' => false, 'products' => false, 'members' => false]);
        $this->withHeaders($h)->getJson("{$api}/reports/summary")->assertForbidden();
        $this->withHeaders($h)->getJson("{$api}/members")->assertForbidden();
        $this->withHeaders($h)->getJson("{$api}/members/search?q=08")->assertOk(); // still needed at the till
        $this->withHeaders($h)->getJson("{$api}/products")->assertOk();

        // Saving from the edit form (no linked_user_id sent) keeps the cashier's login linked.
        $this->withHeaders($owner)->putJson("{$api}/staff/{$staff->id}", ['name' => $staff->name, 'role' => 'cashier', 'permissions' => ['reports' => true]])->assertOk();
        $this->assertSame($staff->linked_user_id, $staff->fresh()->linked_user_id);
        $this->withHeaders($h)->getJson("{$api}/reports/summary")->assertOk();
    }

    public function test_legacy_permissions_and_own_cash_only(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('R');
        // Stored before the catalogue: "transactions" meant the member pages.
        [$h, $staff] = $this->cashier($org, $outlet, ['transactions' => false, 'orders' => false, 'reports' => true]);
        $this->withHeaders($h)->getJson('/api/v1/hellom/pos/members')->assertForbidden();
        $this->withHeaders($h)->getJson('/api/v1/hellom/pos/reports/summary')->assertOk();
        $this->withHeaders($h)->getJson('/api/v1/hellom/pos/orders')->assertOk();

        // Cancel switched off: neither POST …/cancel nor the status endpoint may cancel.
        $staff->forceFill(['permissions' => ['order_cancel' => false]])->save();
        $product = $this->makeProduct($outlet, 10000);
        $order = $this->withHeaders($h)->postJson('/api/v1/hellom/pos/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated()->json('data.order');
        $this->withHeaders($h)->postJson("/api/v1/hellom/pos/orders/{$order['id']}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->withHeaders($h)->patchJson("/api/v1/hellom/pos/orders/{$order['id']}/status", ['status' => 'cancelled', 'reason' => 'x'])
            ->assertForbidden()->assertJsonPath('error.code', 'POS_PERMISSION_DENIED');
        $this->withHeaders($h)->patchJson("/api/v1/hellom/pos/orders/{$order['id']}/status", ['status' => 'accepted'])->assertOk();

        $other = PosStaff::query()->create(['organization_id' => $org->id, 'outlet_id' => $outlet->id, 'tenant_id' => $outlet->tenant_slug,
            'name' => 'Kasir Lain', 'role' => 'cashier', 'employment_status' => 'active']);
        $this->withHeaders($h)->postJson("/api/v1/hellom/pos/staff/{$other->id}/cash/open", ['opening_cash' => 100000])
            ->assertForbidden()->assertJsonPath('error.code', 'POS_PERMISSION_DENIED');
    }
}
