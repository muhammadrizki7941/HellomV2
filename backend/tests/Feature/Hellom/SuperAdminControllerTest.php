<?php

namespace Tests\Feature\Hellom;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminControllerTest extends TestCase
{
    use RefreshDatabase;
    use HellomTestHelpers;

    private function createAdminWithToken(): array
    {
        return $this->createHellomUser(['role' => 'admin']);
    }

    private function seedOrgWithUser(): array
    {
        $org = Organization::query()->create([
            'name' => 'Org One',
            'slug' => 'org-one',
            'status' => 'active',
        ]);

        $user = User::factory()->create(['role' => 'member']);
        $org->users()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        return [$org, $user];
    }

    // ─── Dashboard Stats ───

    public function test_dashboard_stats_ok(): void
    {
        [$admin, $token] = $this->createAdminWithToken();

        $response = $this->getJson('/api/v1/hellom/admin/dashboard-stats', $this->hellomHeaders($token));

        $response->assertOk()
            ->assertJsonStructure(['data' => ['organizations', 'users']]);
    }

    public function test_dashboard_stats_forbidden_for_member(): void
    {
        [$user, $token] = $this->createHellomUser(['role' => 'member']);

        $response = $this->getJson('/api/v1/hellom/admin/dashboard-stats', $this->hellomHeaders($token));

        $response->assertStatus(403);
    }

    // ─── List Organizations ───

    public function test_list_organizations(): void
    {
        [$admin, $token] = $this->createAdminWithToken();
        $this->seedOrgWithUser();

        $response = $this->getJson('/api/v1/hellom/admin/organizations', $this->hellomHeaders($token));

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    // ─── Suspend / Reactivate Organization ───

    public function test_suspend_and_reactivate_organization(): void
    {
        [$admin, $token] = $this->createAdminWithToken();
        [$org] = $this->seedOrgWithUser();

        $response = $this->postJson("/api/v1/hellom/admin/organizations/{$org->id}/suspend", [
            'reason' => 'Test suspend',
        ], $this->hellomHeaders($token));
        $response->assertOk();
        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'status' => 'suspended']);

        $response = $this->postJson("/api/v1/hellom/admin/organizations/{$org->id}/reactivate", [], $this->hellomHeaders($token));
        $response->assertOk();
        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'status' => 'active']);
    }

    // ─── List / Suspend / Reactivate Users ───

    public function test_list_users(): void
    {
        [$admin, $token] = $this->createAdminWithToken();

        $response = $this->getJson('/api/v1/hellom/admin/users', $this->hellomHeaders($token));

        $response->assertOk();
    }

    public function test_suspend_and_reactivate_user(): void
    {
        [$admin, $token] = $this->createAdminWithToken();
        [$org, $member] = $this->seedOrgWithUser();

        $response = $this->postJson("/api/v1/hellom/admin/users/{$member->id}/suspend", [
            'reason' => 'Test ban',
        ], $this->hellomHeaders($token));
        $response->assertOk();

        $response = $this->postJson("/api/v1/hellom/admin/users/{$member->id}/reactivate", [], $this->hellomHeaders($token));
        $response->assertOk();
    }

    // ─── Audit Logs ───

    public function test_audit_logs(): void
    {
        [$admin, $token] = $this->createAdminWithToken();

        $response = $this->getJson('/api/v1/hellom/admin/audit-logs', $this->hellomHeaders($token));

        $response->assertOk()
            ->assertJsonStructure(['data' => ['items']]);
    }

    public function test_override_entitlement_active_pos_provisions_pos_mapping(): void
    {
        [$admin, $token] = $this->createAdminWithToken();
        [$org] = $this->seedOrgWithUser();

        $response = $this->postJson('/api/v1/hellom/admin/entitlements/override', [
            'organization_id' => $org->id,
            'app_slug' => 'pos',
            'status' => 'active',
        ], $this->hellomHeaders($token));

        $response->assertOk()
            ->assertJsonPath('data.entitlement.status', 'active');

        $org = $org->fresh();

        $this->assertNotNull($org?->pos_tenant_slug);
        $this->assertNotNull($org?->pos_tenant_name);
        $this->assertNotNull($org?->pos_provisioned_at);

        $this->assertDatabaseHas('product_purchase_settings', [
            'organization_id' => $org->id,
            'service_type' => 'dine_in',
        ]);
    }
}
