<?php

namespace Tests\Pos;

use App\Models\ApiToken;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

class BusinessProfileTest extends PosTestCase
{
    use DatabaseTransactions;

    public function test_new_organization_uses_fnb_preset_and_returns_module_metadata(): void
    {
        ['org' => $organization] = $this->makeOrganization();
        $headers = $this->managerHeaders($organization);

        $this->withHeaders($headers)
            ->getJson('/api/v1/hellom/pos/business-profile')
            ->assertOk()
            ->assertJsonPath('data.business_type', 'fnb')
            ->assertJsonPath('data.onboarding_completed', false)
            ->assertJsonPath('data.enabled_modules.0', 'tables_selforder')
            ->assertJsonPath('data.modules.0.enabled', true)
            ->assertJsonPath('data.business_types.0.key', 'fnb')
            ->assertJsonPath('data.module_presets.retail.0', 'barcode')
            ->assertJsonPath('data.organization_id', $organization->id);
    }

    public function test_changing_business_type_uses_its_preset_and_gates_table_endpoints(): void
    {
        ['org' => $organization, 'outlet' => $outlet] = $this->makeOrganization();
        $table = $this->makeTable($outlet);
        $product = $this->makeProduct($outlet, 20000);
        $headers = $this->managerHeaders($organization);

        $this->withHeaders($headers)
            ->putJson('/api/v1/hellom/pos/business-profile', ['business_type' => 'konter_hp'])
            ->assertOk()
            ->assertJsonPath('data.business_type', 'konter_hp')
            ->assertJsonPath('data.enabled_modules.0', 'barcode');

        $this->withHeaders($headers)
            ->getJson('/api/v1/hellom/pos/tables')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'POS_MODULE_DISABLED')
            ->assertJsonPath('error.module', 'tables_selforder');

        $this->getJson("/api/v1/hellom/pos/customer/organization/{$organization->slug}/outlets")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'POS_MODULE_DISABLED');

        $tableOrder = [
            'table_id' => $table->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];
        $this->withHeaders($headers)
            ->postJson('/api/v1/hellom/pos/orders', $tableOrder)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'POS_MODULE_DISABLED');
        $this->withHeaders($headers)
            ->postJson('/api/v1/hellom/pos/orders/preview', $tableOrder)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'POS_MODULE_DISABLED');
    }

    public function test_owner_can_override_modules_without_changing_the_business_type(): void
    {
        ['org' => $organization] = $this->makeOrganization();
        $headers = $this->managerHeaders($organization);

        $this->withHeaders($headers)
            ->putJson('/api/v1/hellom/pos/business-profile', [
                'enabled_modules' => ['barcode', 'serial_imei'],
                'onboarding_completed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.business_type', 'fnb')
            ->assertJsonPath('data.onboarding_completed', true)
            ->assertJsonPath('data.enabled_modules.0', 'barcode')
            ->assertJsonPath('data.enabled_modules.1', 'serial_imei');

        $this->withHeaders($headers)
            ->getJson('/api/v1/hellom/pos/tables')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'POS_MODULE_DISABLED');
    }

    private function managerHeaders(Organization $organization): array
    {
        $user = User::query()->create([
            'name' => 'Owner POS',
            'email' => Str::lower(Str::random(10)) . '@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'tenant_admin',
            'current_organization_id' => $organization->id,
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner']);

        $app = AppCatalog::query()->firstOrCreate(
            ['slug' => 'pos'],
            ['name' => 'POS', 'is_active' => true]
        );
        Entitlement::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'app_id' => $app->id],
            ['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]
        );

        $plainToken = Str::random(40);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'business-profile-test',
            'token_hash' => hash('sha256', $plainToken),
        ]);

        return [
            'Authorization' => 'Bearer ' . $plainToken,
            'Accept' => 'application/json',
        ];
    }
}
