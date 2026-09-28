<?php

namespace Tests\Pos;

use App\Models\ApiToken;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\PosStaff;
use App\Models\User;
use App\Services\Pos\MemberService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

/** Cashier endpoints end to end: outlet scoping, server totals, payment ≠ kitchen status, permissions. */
class CashierApiTest extends PosTestCase
{
    use DatabaseTransactions;

    private function tokenFor(Organization $org, string $pivotRole, ?Outlet $cashierOutlet = null): string
    {
        $user = User::query()->create([
            'name' => 'User ' . Str::random(4),
            'email' => Str::lower(Str::random(10)) . '@example.test',
            'password' => bcrypt('secret-password'),
            'role' => $pivotRole === 'owner' ? 'tenant_admin' : 'cashier',
            'current_organization_id' => $org->id,
        ]);
        $org->users()->attach($user->id, ['role' => $pivotRole]);
        if ($cashierOutlet) {
            PosStaff::query()->create([
                'organization_id' => $org->id,
                'outlet_id' => $cashierOutlet->id,
                'tenant_id' => $cashierOutlet->tenant_slug,
                'linked_user_id' => $user->id,
                'name' => $user->name,
                'role' => 'cashier',
                'employment_status' => 'active',
            ]);
        }

        $app = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        Entitlement::query()->firstOrCreate(
            ['organization_id' => $org->id, 'app_id' => $app->id],
            ['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]
        );

        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 'test', 'token_hash' => hash('sha256', $plain)]);

        return $plain;
    }

    public function test_cashier_flow_prices_on_server_and_keeps_payment_separate(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('A', ['pricing' => ['tax_percent' => 10, 'service_percent' => 0, 'rounding' => 0]]);
        $product = $this->makeProduct($outlet, 20000);
        $cashier = $this->tokenFor($org, 'cashier', $outlet);
        $headers = ['Authorization' => "Bearer {$cashier}"];

        $created = $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'final_amount' => 1, // client totals are ignored
        ])->assertCreated()->json('data.order');
        $this->assertSame(44000, $created['final_amount']);
        $this->assertSame(4000, $created['tax_amount']);

        $id = $created['id'];
        $this->withHeaders($headers)->patchJson("/api/v1/hellom/pos/orders/{$id}/status", ['status' => 'dikonfirmasi'])
            ->assertOk()->assertJsonPath('data.order.status', 'accepted')->assertJsonPath('data.order.status_label', 'Dikonfirmasi');
        $this->withHeaders($headers)->patchJson("/api/v1/hellom/pos/orders/{$id}/status", ['status' => 'new'])
            ->assertStatus(422)->assertJsonPath('error.code', 'ILLEGAL_TRANSITION');

        $this->withHeaders($headers)->postJson("/api/v1/hellom/pos/orders/{$id}/payment", ['payment_method' => 'cash', 'payment_amount' => 50000])
            ->assertOk()->assertJsonPath('data.change_amount', 6000)->assertJsonPath('data.order.status', 'accepted');

        // Refund is for owner/supervisor only.
        $this->withHeaders($headers)->postJson("/api/v1/hellom/pos/orders/{$id}/refund", ['reason' => 'test'])->assertForbidden();
        $owner = $this->tokenFor($org, 'owner');
        $this->withHeaders(['Authorization' => "Bearer {$owner}", 'X-Outlet-Id' => (string) $outlet->id])
            ->postJson("/api/v1/hellom/pos/orders/{$id}/refund", ['reason' => 'Salah input'])
            ->assertOk()->assertJsonPath('data.order.payment_status', 'refunded');
    }

    public function test_cashier_is_locked_to_own_outlet_and_members_are_shared(): void
    {
        ['org' => $org, 'outlet' => $outletA] = $this->makeOrganization('A');
        $outletB = $this->makeOutlet($org);
        $productB = $this->makeProduct($outletB, 10000);
        [$member] = app(MemberService::class)->register($org, 'Ani', '081233334444', null, $outletB->id, $outletB->tenant_slug);

        $cashierA = $this->tokenFor($org, 'cashier', $outletA);
        $headers = ['Authorization' => "Bearer {$cashierA}", 'X-Outlet-Id' => (string) $outletB->id]; // header ignored for cashiers

        $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/orders', ['items' => [['product_id' => $productB->id, 'quantity' => 1]]])
            ->assertStatus(409)->assertJsonPath('error.problems.0.reason', 'not_found');

        // Member registered at outlet B is found by the cashier of outlet A (same organization).
        $this->withHeaders($headers)->getJson('/api/v1/hellom/pos/members/search?q=0812-3333')
            ->assertOk()->assertJsonPath('data.members.0.id', $member->id);
        $this->withHeaders($headers)->postJson('/api/v1/hellom/pos/members', ['name' => 'Ani Dua', 'phone' => '+62 812 3333 4444'])
            ->assertStatus(422)->assertJsonPath('error.code', 'MEMBER_EXISTS');

        // Manual point adjustment needs a supervisor.
        $this->withHeaders($headers)->postJson("/api/v1/hellom/pos/members/{$member->id}/adjust-points", ['points' => 10, 'reason' => 'bonus'])->assertForbidden();
    }
}
