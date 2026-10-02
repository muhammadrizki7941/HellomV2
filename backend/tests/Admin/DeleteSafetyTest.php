<?php

namespace Tests\Admin;

use App\Models\AppCatalog;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** P0-2: deleting plans/users never takes billing history or money ledgers with it. */
class DeleteSafetyTest extends AdminTestCase
{
    private function plan(): Plan
    {
        return Plan::query()->create(['slug' => 'tes-' . Str::lower(Str::random(6)), 'name' => 'Tes', 'type' => 'subscription', 'price' => 1000, 'is_active' => true, 'is_visible' => true]);
    }

    public function test_plan_with_history_is_archived_and_unused_plan_is_deleted(): void
    {
        ['org' => $org] = $this->makeOrganization('PLAN');
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        $used = $this->plan();
        $subscription = Subscription::query()->create(['organization_id' => $org->id, 'app_id' => $app->id, 'plan_id' => $used->id, 'status' => 'expired', 'amount' => 1000, 'currency' => 'IDR', 'billing_cycle' => 'monthly']);
        $unused = $this->plan();
        $admin = $this->superAdmin();

        $this->api($admin, 'DELETE', "/admin/plans/{$used->id}")->assertOk()->assertJsonPath('data.archived', true);
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id]);
        $this->assertFalse((bool) $used->fresh()->is_active);
        $this->assertFalse((bool) $used->fresh()->is_visible);

        $this->api($admin, 'DELETE', "/admin/plans/{$unused->id}")->assertOk()->assertJsonPath('data.archived', false);
        $this->assertDatabaseMissing('plans', ['id' => $unused->id]);
    }

    public function test_user_delete_is_refused_for_self_super_admin_sole_owner_and_money_history(): void
    {
        $admin = $this->superAdmin();
        $otherAdmin = $this->superAdmin();
        ['org' => $org] = $this->makeOrganization('DEL');
        $owner = $this->makeUser('admin', $org, 'owner');
        $buyer = $this->makeUser('member');
        DB::table('user_wallet_ledgers')->insert(['user_id' => $buyer->id, 'type' => 'deposit', 'amount' => 5000, 'balance_after' => 5000, 'created_at' => now(), 'updated_at' => now()]);
        $plain = $this->makeUser('member');

        $this->api($admin, 'DELETE', "/admin/users/{$admin->id}")->assertStatus(422)->assertJsonPath('error.code', 'CANNOT_DELETE_SELF');
        $this->api($admin, 'DELETE', "/admin/users/{$otherAdmin->id}")->assertStatus(422)->assertJsonPath('error.code', 'CANNOT_DELETE_SUPER_ADMIN');
        $this->api($admin, 'DELETE', "/admin/users/{$owner->id}")->assertStatus(422)->assertJsonPath('error.code', 'USER_IS_SOLE_OWNER');
        $this->api($admin, 'DELETE', "/admin/users/{$buyer->id}")->assertStatus(422)->assertJsonPath('error.code', 'USER_HAS_PAYMENT_HISTORY');
        $this->assertDatabaseHas('user_wallet_ledgers', ['user_id' => $buyer->id]);

        $this->api($admin, 'DELETE', "/admin/users/{$plain->id}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $plain->id]);
    }

    public function test_non_super_admin_cannot_reach_admin_endpoints(): void
    {
        ['org' => $org] = $this->makeOrganization('GUARD');
        $owner = $this->makeUser('admin', $org, 'owner');

        $this->api($owner, 'GET', '/admin/users')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN_SUPER_ADMIN');
        $this->api($owner, 'DELETE', "/admin/users/{$owner->id}")->assertForbidden();
        $this->flushHeaders()->getJson('/api/v1/hellom/admin/users')->assertUnauthorized();
    }
}
