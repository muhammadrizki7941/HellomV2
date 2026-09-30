<?php

namespace Tests\Pos;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\PosStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

/** POST /auth/staff-login: cashiers land in the store where they are POS staff. */
class StaffLoginTest extends PosTestCase
{
    use DatabaseTransactions;

    private const URL = '/api/v1/hellom/auth/staff-login';

    private function store(string $label): array
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization($label);
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        Entitlement::query()->firstOrCreate(['organization_id' => $org->id, 'app_id' => $app->id],
            ['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);

        return [$org, $outlet];
    }

    private function account(bool $verified = true, ?Organization $ownOrg = null): User
    {
        $user = User::query()->create(['name' => 'Sinta', 'email' => 'sinta-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('rahasia-123'), 'role' => 'member', 'current_organization_id' => $ownOrg?->id]);
        if ($verified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        if ($ownOrg) {
            $ownOrg->users()->attach($user->id, ['role' => 'owner']);
        }

        return $user;
    }

    private function staff(Organization $org, Outlet $outlet, array $attributes): PosStaff
    {
        return PosStaff::query()->create($attributes + ['organization_id' => $org->id, 'outlet_id' => $outlet->id, 'tenant_id' => $outlet->tenant_slug,
            'name' => 'Sinta', 'role' => 'cashier', 'employment_status' => 'active']);
    }

    public function test_linked_cashier_lands_in_the_staff_store_not_her_own_business(): void
    {
        [$ownOrg] = $this->store('OWN');
        [$shop, $outlet] = $this->store('SHOP');
        $user = $this->account(true, $ownOrg); // she also owns another business
        $this->staff($shop, $outlet, ['linked_user_id' => $user->id]);

        // Normal login keeps her own business…
        $this->postJson('/api/v1/hellom/auth/login', ['email' => $user->email, 'password' => 'rahasia-123'])
            ->assertOk()->assertJsonPath('data.user.current_organization.id', $ownOrg->id);
        // …staff login goes straight into the shop, as its cashier.
        $res = $this->postJson(self::URL, ['email' => strtoupper($user->email), 'password' => 'rahasia-123'])->assertOk()
            ->assertJsonPath('data.user.current_organization.id', $shop->id)
            ->assertJsonPath('data.user.pos_access.is_cashier', true)
            ->assertJsonPath('data.user.pos_access.outlet_id', $outlet->id);
        $this->withHeaders(['Authorization' => 'Bearer ' . $res->json('data.token')])->getJson('/api/v1/hellom/pos/orders')->assertOk();
    }

    public function test_staff_added_by_email_links_only_a_verified_account(): void
    {
        [$shop, $outlet] = $this->store('MAIL');
        $unverified = $this->account(false);
        $staff = $this->staff($shop, $outlet, ['email' => strtoupper($unverified->email)]);

        $this->postJson(self::URL, ['email' => $unverified->email, 'password' => 'rahasia-123'])
            ->assertForbidden()->assertJsonPath('error.code', 'STAFF_EMAIL_UNVERIFIED');
        $this->assertNull($staff->fresh()->linked_user_id);

        $unverified->forceFill(['email_verified_at' => now()])->save();
        $this->postJson(self::URL, ['email' => $unverified->email, 'password' => 'rahasia-123'])->assertOk()
            ->assertJsonPath('data.user.current_organization.id', $shop->id)
            ->assertJsonPath('data.user.pos_access.is_cashier', true);
        $this->assertSame($unverified->id, $staff->fresh()->linked_user_id);
        $this->assertSame('cashier', $shop->users()->where('users.id', $unverified->id)->first()->pivot->role);
    }

    public function test_several_stores_ask_to_choose_and_other_cases_are_refused(): void
    {
        [$a, $outletA] = $this->store('A');
        [$b, $outletB] = $this->store('B');
        $user = $this->account();
        $this->staff($a, $outletA, ['linked_user_id' => $user->id]);
        $staffB = $this->staff($b, $outletB, ['linked_user_id' => $user->id]);

        $choices = $this->postJson(self::URL, ['email' => $user->email, 'password' => 'rahasia-123'])
            ->assertStatus(409)->assertJsonPath('error.code', 'STAFF_CHOOSE_STORE')->json('error.choices');
        $this->assertCount(2, $choices);
        $this->postJson(self::URL, ['email' => $user->email, 'password' => 'rahasia-123', 'staff_id' => $staffB->id])->assertOk()
            ->assertJsonPath('data.user.current_organization.id', $b->id);
        // A staff id that is not hers is ignored → asked to choose again.
        $this->postJson(self::URL, ['email' => $user->email, 'password' => 'rahasia-123', 'staff_id' => 999999])->assertStatus(409);

        $this->postJson(self::URL, ['email' => $user->email, 'password' => 'salah-sandi'])->assertStatus(401);

        // Inactive staff / not staff anywhere → refused with a clear code.
        PosStaff::query()->where('linked_user_id', $user->id)->update(['employment_status' => 'inactive']);
        $this->postJson(self::URL, ['email' => $user->email, 'password' => 'rahasia-123'])
            ->assertForbidden()->assertJsonPath('error.code', 'NOT_POS_STAFF');
    }
}
