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
use Illuminate\Support\Facades\Http;
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
            ['POST', "{$api}/purchases", []],
            ['GET', "{$api}/members/export"],
            ['PUT', "{$api}/loyalty/settings", []],
            ['GET', "{$api}/customer-experience/dashboard"],
            ['PUT', "{$api}/outlet-settings", []],
        ] as $call) {
            $this->withHeaders($h)->json($call[0], $call[1], $call[2] ?? [])
                ->assertForbidden()->assertJsonPath('error.code', 'POS_PERMISSION_DENIED');
        }
        // Owner/admin only, never grantable.
        foreach ([
            ['GET', "{$api}/staff"],
            ['POST', "{$api}/outlets", ['name' => 'X']],
            ['GET', "{$api}/payment-settings"],
            ['GET', "{$api}/reports/margin"],
            ['GET', "{$api}/reports/gross-profit"],
        ] as $call) {
            $this->withHeaders($h)->json($call[0], $call[1], $call[2] ?? [])->assertForbidden();
        }
        $this->withHeaders($h)->getJson("{$api}/me/access")->assertOk()
            ->assertJsonPath('data.is_cashier', true)
            ->assertJsonPath('data.permissions.orders', true)
            ->assertJsonPath('data.permissions.products', false)
            ->assertJsonPath('data.permissions.members', true)
            ->assertJsonPath('data.permissions.tables', true); // owner decision: on by default
        $this->withHeaders($h)->postJson("{$api}/stock-transfers", [
            'destination_outlet_id' => $outlet->id + 1,
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ])->assertForbidden()->assertJsonPath('error.code', 'POS_PERMISSION_DENIED');
    }

    public function test_outlet_admin_is_limited_to_assigned_outlets_and_cashier_accounts(): void
    {
        ['org' => $org, 'outlet' => $outletA] = $this->makeOrganization('OUTLET-ADMIN');
        $outletB = $this->makeOutlet($org);
        [$headers, $user] = $this->login($org, 'cashier');
        $headers['X-Outlet-Id'] = (string) $outletA->id;

        $outletAdmin = PosStaff::query()->create([
            'organization_id' => $org->id,
            'outlet_id' => $outletA->id,
            'tenant_id' => $outletA->tenant_slug,
            'linked_user_id' => $user->id,
            'name' => $user->name,
            'role' => 'admin',
            'employment_status' => 'active',
        ]);
        $outletAdmin->outlets()->sync([$outletA->id]);
        $cashierA = PosStaff::query()->create([
            'organization_id' => $org->id,
            'outlet_id' => $outletA->id,
            'tenant_id' => $outletA->tenant_slug,
            'name' => 'Kasir A',
            'role' => 'cashier',
            'employment_status' => 'active',
        ]);
        $cashierA->outlets()->sync([$outletA->id]);
        $cashierB = PosStaff::query()->create([
            'organization_id' => $org->id,
            'outlet_id' => $outletB->id,
            'tenant_id' => $outletB->tenant_slug,
            'name' => 'Kasir B',
            'role' => 'cashier',
            'employment_status' => 'active',
        ]);
        $cashierB->outlets()->sync([$outletB->id]);
        $transferProduct = $this->makeProduct($outletA, 10000, ['sku' => 'OUTLET-ADMIN-TRANSFER']);

        $dashboard = $this->withHeaders($headers)->getJson('/api/v1/hellom/pos/staff')
            ->assertOk()
            ->assertJsonPath('data.meta.roles.0', 'cashier')
            ->assertJsonCount(2, 'data.staff');
        $this->assertNotContains($cashierB->id, collect($dashboard->json('data.staff'))->pluck('id')->all());

        $this->withHeaders($headers)->getJson('/api/v1/hellom/pos/outlets')
            ->assertOk()
            ->assertJsonCount(1, 'data.outlets')
            ->assertJsonPath('data.outlets.0.id', $outletA->id);
        $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/stock-transfers', [
            'destination_outlet_id' => $outletB->id,
            'items' => [['product_id' => $transferProduct->id, 'qty' => 1]],
        ])->assertForbidden()->assertJsonPath('error.code', 'POS_OUTLET_NOT_ASSIGNED');
        $this->withHeaders(array_merge($headers, ['X-Outlet-Id' => (string) $outletB->id]))
            ->getJson('/api/v1/hellom/pos/products')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'POS_OUTLET_NOT_ASSIGNED');

        $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/staff', [
            'name' => 'Kasir Baru',
            'role' => 'cashier',
            'outlet_ids' => [$outletA->id],
        ])->assertCreated()->assertJsonPath('data.staff.business_role', 'cashier');
        $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/staff', [
            'name' => 'Admin Baru',
            'role' => 'admin',
            'outlet_ids' => [$outletA->id],
        ])->assertForbidden()->assertJsonPath('error.code', 'STAFF_ROLE_FORBIDDEN');
        $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/staff', [
            'name' => 'Kasir Outlet B',
            'role' => 'cashier',
            'outlet_ids' => [$outletB->id],
        ])->assertForbidden()->assertJsonPath('error.code', 'STAFF_OUTLET_FORBIDDEN');
        $this->withHeaders($headers)->putJson("/api/v1/hellom/pos/staff/{$cashierB->id}", [
            'name' => 'Kasir Diambil Alih',
            'role' => 'cashier',
        ])->assertNotFound();
        $this->withHeaders($headers)->deleteJson("/api/v1/hellom/pos/staff/{$cashierB->id}")
            ->assertNotFound();
    }

    public function test_cashier_cannot_read_cost_price_even_with_product_permission(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('HPP');
        $product = $this->makeProduct($outlet, 25000, ['cost_price' => 12000]);
        [$headers] = $this->cashier($org, $outlet, ['products' => true]);
        $headers['X-Outlet-Id'] = (string) $outlet->id;

        $this->withHeaders($headers)->getJson('/api/v1/hellom/pos/products')
            ->assertOk()
            ->assertJsonMissingPath('data.products.0.cost_price');
        $this->withHeaders($headers)->patchJson("/api/v1/hellom/pos/products/{$product->id}", [
            'cost_price' => 1,
        ])->assertForbidden()->assertJsonPath('error.code', 'COST_PRICE_FORBIDDEN');
        $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/products', [
            'cost_price' => 1,
        ])->assertForbidden()->assertJsonPath('error.code', 'COST_PRICE_FORBIDDEN');
    }

    public function test_outlet_admin_can_transfer_only_between_assigned_outlets(): void
    {
        ['org' => $org, 'outlet' => $outletA] = $this->makeOrganization('TRANSFER-ADMIN');
        $outletB = $this->makeOutlet($org);
        [$headers, $user] = $this->login($org, 'cashier');
        $headers['X-Outlet-Id'] = (string) $outletA->id;

        $admin = PosStaff::query()->create([
            'organization_id' => $org->id,
            'outlet_id' => $outletA->id,
            'tenant_id' => $outletA->tenant_slug,
            'linked_user_id' => $user->id,
            'name' => $user->name,
            'role' => 'admin',
            'employment_status' => 'active',
        ]);
        $admin->outlets()->sync([$outletA->id, $outletB->id]);
        $sourceProduct = $this->makeProduct($outletA, 20000, [
            'sku' => 'ADMIN-TRANSFER-001',
            'track_stock' => true,
            'stock' => 0,
        ]);
        $this->makeProduct($outletB, 20000, [
            'sku' => 'ADMIN-TRANSFER-001',
            'track_stock' => true,
            'stock' => 0,
        ]);
        app(\App\Services\Pos\StockMovementService::class)->setOnHand($sourceProduct, 2, $outletA->id, null, 'Stok awal tes');

        $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/stock-transfers', [
            'destination_outlet_id' => $outletB->id,
            'items' => [['product_id' => $sourceProduct->id, 'qty' => 1]],
        ])->assertCreated();
    }

    public function test_outlet_admin_can_create_cashier_account_without_email_and_force_password_change(): void
    {
        ['org' => $org, 'outlet' => $outletA] = $this->makeOrganization('ACCOUNT');
        $outletB = $this->makeOutlet($org);
        [$headers, $adminUser] = $this->login($org, 'cashier');
        $adminStaff = PosStaff::query()->create([
            'organization_id' => $org->id,
            'outlet_id' => $outletA->id,
            'tenant_id' => $outletA->tenant_slug,
            'linked_user_id' => $adminUser->id,
            'name' => $adminUser->name,
            'role' => 'admin',
            'employment_status' => 'active',
        ]);
        $adminStaff->outlets()->sync([$outletA->id]);
        $headers['X-Outlet-Id'] = (string) $outletA->id;

        $staffList = $this->withHeaders($headers)->getJson('/api/v1/hellom/pos/staff')
            ->assertOk();
        $this->assertSame([$adminStaff->id], collect($staffList->json('data.staff'))->pluck('id')->all());

        $created = $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/staff', [
            'name' => 'Kasir Baru',
            'role' => 'cashier',
            'outlet_ids' => [$outletA->id],
            'employment_status' => 'active',
            'create_login' => true,
            'username' => 'kasir' . Str::lower(Str::random(8)),
        ])->assertCreated()
            ->assertJsonPath('data.credentials.must_change_password', true);

        $credentials = $created->json('data.credentials');
        $newUser = User::query()->where('username', $credentials['identifier'])->firstOrFail();
        $newStaff = PosStaff::query()->where('linked_user_id', $newUser->id)->firstOrFail();
        $this->assertNull($newUser->email);
        $this->assertSame($adminUser->id, $newStaff->created_by_user_id);

        $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/staff', [
            'name' => 'Kasir Outlet Lain',
            'role' => 'cashier',
            'outlet_ids' => [$outletB->id],
            'create_login' => true,
            'username' => 'other' . Str::lower(Str::random(8)),
        ])->assertForbidden()->assertJsonPath('error.code', 'STAFF_OUTLET_FORBIDDEN');
        $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/staff', [
            'name' => 'Admin Baru',
            'role' => 'admin',
            'outlet_ids' => [$outletA->id],
        ])->assertForbidden()->assertJsonPath('error.code', 'STAFF_ROLE_FORBIDDEN');

        $login = $this->postJson('/api/v1/hellom/auth/staff-login', [
            'identifier' => $credentials['identifier'],
            'password' => $credentials['password'],
        ])->assertOk()->assertJsonPath('data.user.must_change_password', true);
        $staffToken = $login->json('data.token');
        $this->withToken($staffToken)->getJson('/api/v1/hellom/pos/me/access')
            ->assertForbidden()->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
        $this->withToken($staffToken)->postJson('/api/v1/hellom/auth/change-password', [
            'current_password' => $credentials['password'],
            'password' => 'New-safe-password-298',
            'password_confirmation' => 'New-safe-password-298',
        ])->assertOk();
        $this->withToken($staffToken)->getJson('/api/v1/hellom/pos/me/access')
            ->assertOk()->assertJsonPath('data.is_cashier', true);
    }

    public function test_deactivation_and_role_change_revoke_existing_staff_tokens(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('TOKEN-REVOKE');
        [$ownerHeaders] = $this->login($org, 'owner');
        $ownerHeaders['X-Outlet-Id'] = (string) $outlet->id;
        [$cashierHeaders, $cashier] = $this->cashier($org, $outlet);
        $api = '/api/v1/hellom/pos';

        $this->withHeaders($ownerHeaders)->deleteJson("{$api}/staff/{$cashier->id}")->assertOk();
        $this->withHeaders($cashierHeaders)->getJson("{$api}/me/access")->assertUnauthorized();

        [$activeCashierHeaders, $activeCashier] = $this->cashier($org, $outlet);
        $this->withHeaders($ownerHeaders)->putJson("{$api}/staff/{$activeCashier->id}", [
            'name' => $activeCashier->name,
            'role' => 'admin',
            'outlet_ids' => [$outlet->id],
            'employment_status' => 'active',
        ])->assertOk();
        $this->withHeaders($activeCashierHeaders)->getJson("{$api}/me/access")->assertUnauthorized();

        [$resetCashierHeaders, $resetCashier] = $this->cashier($org, $outlet);
        $this->withHeaders($ownerHeaders)->postJson("{$api}/staff/{$resetCashier->id}/reset-password")
            ->assertOk()
            ->assertJsonPath('data.credentials.must_change_password', true);
        $this->withHeaders($resetCashierHeaders)->getJson("{$api}/me/access")->assertUnauthorized();

        $secondOutlet = $this->makeOutlet($org);
        [$reassignedHeaders, $reassignedCashier] = $this->cashier($org, $outlet);
        $this->withHeaders($ownerHeaders)->putJson("{$api}/staff/{$reassignedCashier->id}", [
            'name' => $reassignedCashier->name,
            'role' => 'cashier',
            'outlet_ids' => [$secondOutlet->id],
            'employment_status' => 'active',
        ])->assertOk();
        $this->withHeaders($reassignedHeaders)->getJson("{$api}/me/access")->assertUnauthorized();
    }

    public function test_organization_role_change_revokes_api_token_and_realtime_session(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('ORG-REVOKE');
        [$ownerHeaders] = $this->login($org, 'owner');
        [$cashierHeaders, $cashier] = $this->cashier($org, $outlet);
        $cashierUser = User::query()->findOrFail($cashier->linked_user_id);

        config([
            'realtime.enabled' => true,
            'realtime.server_url' => 'https://realtime.example.test',
            'realtime.secret' => 'test-secret',
        ]);
        Http::fake();

        $this->withHeaders($ownerHeaders)->putJson(
            "/api/v1/hellom/organizations/current/team/{$cashierUser->id}/role",
            ['role' => 'admin']
        )->assertOk();

        $this->withHeaders($cashierHeaders)->getJson('/api/v1/hellom/pos/me/access')
            ->assertUnauthorized();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://realtime.example.test/emit'
            && $request['room'] === 'user_'.$cashierUser->id
            && $request['event'] === 'auth.revoked'
            && $request->hasHeader('X-RT-SECRET', 'test-secret')
        );
    }

    public function test_cashier_profile_updates_own_staff_card_without_changing_access(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('PROFILE');
        [$headers, $staff] = $this->cashier($org, $outlet);
        $user = User::query()->findOrFail($staff->linked_user_id);

        $profile = $this->withHeaders($headers)->getJson('/api/v1/hellom/pos/me/profile')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.staff.id', $staff->id)
            ->assertJsonPath('data.staff.role', 'cashier')
            ->assertJsonPath('data.staff.attendance_qr.payload.staff_id', $staff->id)
            ->assertJsonPath('data.staff.attendance_qr.payload.staff_name', $staff->name);

        $qrToken = $profile->json('data.staff.attendance_qr.payload.token');
        $this->assertNotEmpty($qrToken);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $profile->json('data.staff.attendance_qr.svg_data_uri'));

        $this->withHeaders($headers)->putJson('/api/v1/hellom/pos/me/profile', [
            'name' => 'Kasir Profil Baru',
            'email' => 'cashier-profile@example.test',
            'phone' => '081234567890',
            'role' => 'admin',
            'outlet_ids' => [],
        ])->assertOk()
            ->assertJsonPath('data.user.name', 'Kasir Profil Baru')
            ->assertJsonPath('data.staff.name', 'Kasir Profil Baru')
            ->assertJsonPath('data.staff.role', 'cashier')
            ->assertJsonPath('data.staff.attendance_qr.payload.token', $qrToken);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Kasir Profil Baru',
            'email' => 'cashier-profile@example.test',
            'phone' => '081234567890',
        ]);
        $this->assertDatabaseHas('pos_staff', [
            'id' => $staff->id,
            'name' => 'Kasir Profil Baru',
            'role' => 'cashier',
            'outlet_id' => $outlet->id,
        ]);
    }

    public function test_legacy_staff_without_organization_id_keeps_outlet_access(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('LEGACY-STAFF');
        [$headers, $user] = $this->login($org, 'cashier');
        $headers['X-Outlet-Id'] = (string) $outlet->id;
        $legacyAdmin = PosStaff::query()->create([
            'organization_id' => null,
            'outlet_id' => $outlet->id,
            'tenant_id' => $outlet->tenant_slug,
            'linked_user_id' => $user->id,
            'name' => $user->name,
            'role' => 'admin',
            'employment_status' => 'active',
        ]);
        $legacyAdmin->outlets()->sync([$outlet->id]);
        $this->withHeaders($headers)->getJson('/api/v1/hellom/pos/me/access')
            ->assertOk()
            ->assertJsonPath('data.business_role', 'outlet_admin');
        $this->withHeaders($headers)->getJson('/api/v1/hellom/pos/staff')
            ->assertOk()
            ->assertJsonPath('data.current_user_staff_id', $legacyAdmin->id);
    }

    public function test_cashier_cannot_view_organization_team_settings_or_billing(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('ORG-ADMIN-ONLY');
        [$cashierHeaders] = $this->cashier($org, $outlet);
        [$ownerHeaders] = $this->login($org, 'owner');

        foreach ([
            '/api/v1/hellom/organizations/current/team',
            '/api/v1/hellom/organizations/current/settings',
            '/api/v1/hellom/billing/overview',
            '/api/v1/hellom/billing/history',
        ] as $path) {
            $this->withHeaders($cashierHeaders)->getJson($path)
                ->assertForbidden()
                ->assertJsonPath('error.code', 'ORGANIZATION_ADMIN_REQUIRED');
            $this->withHeaders($ownerHeaders)->getJson($path)->assertOk();
        }
    }

    public function test_organization_admin_can_create_outlet_admin_and_only_sees_own_tenant_staff(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('OWNER-STAFF');
        ['org' => $otherOrg, 'outlet' => $otherOutlet] = $this->makeOrganization('OTHER-STAFF');
        [$ownerHeaders, $owner] = $this->login($org, 'owner');
        $ownerHeaders['X-Outlet-Id'] = (string) $outlet->id;
        $username = 'owner-created-' . Str::lower(Str::random(8));

        $createdAdmin = $this->withHeaders($ownerHeaders)->postJson('/api/v1/hellom/pos/staff', [
            'name' => 'Admin Outlet Baru',
            'role' => 'admin',
            'outlet_ids' => [$outlet->id],
            'create_login' => true,
            'username' => $username,
        ])->assertCreated()
            ->assertJsonPath('data.staff.business_role', 'outlet_admin')
            ->assertJsonPath('data.staff.created_by.id', $owner->id);
        $createdAdminId = $createdAdmin->json('data.staff.id');

        [, $localCashier] = $this->cashier($org, $outlet);
        [, $otherTenantCashier] = $this->cashier($otherOrg, $otherOutlet);
        $staffList = $this->withHeaders($ownerHeaders)->getJson('/api/v1/hellom/pos/staff')->assertOk();
        $visibleIds = collect($staffList->json('data.staff'))->pluck('id')->all();
        $createdStaff = collect($staffList->json('data.staff'))->firstWhere('id', $createdAdminId);

        $this->assertContains($createdAdminId, $visibleIds);
        $this->assertSame($owner->id, $createdStaff['created_by']['id']);
        $this->assertContains($localCashier->id, $visibleIds);
        $this->assertNotContains($otherTenantCashier->id, $visibleIds);
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

        // Blind closing: the cashier never sees what the system expects.
        $this->withHeaders($h)->getJson("{$api}/me/cash")->assertOk()
            ->assertJsonPath('data.blind', true)
            ->assertJsonPath('data.open.opening_cash', 200000)
            ->assertJsonPath('data.open.live_cash_sales', null)
            ->assertJsonPath('data.open.live_expected_cash', null);

        $closed = $this->withHeaders($h)->postJson("{$api}/staff/{$staff->id}/cash/close", ['closing_cash' => 200000 + (int) $order['final_amount'] - 5000])
            ->assertOk()->json('data.cash_log');
        $this->assertSame('closed', $closed['status']);
        $this->assertNull($closed['difference_cash']);
        $this->assertNull($closed['expected_cash']);
        $this->assertSame(-5000, (int) \App\Models\PosStaffCashLog::query()->find($closed['id'])->difference_cash);
        $this->withHeaders($h)->getJson("{$api}/me/cash")->assertJsonPath('data.open', null)->assertJsonPath('data.last_closed.difference_cash', null);

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
        $this->withHeaders($h)->postJson("{$api}/orders/{$order['id']}/refund", ['reason' => 'Salah input', 'approval_pin' => $this->ownerPin($org)])->assertOk();
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
