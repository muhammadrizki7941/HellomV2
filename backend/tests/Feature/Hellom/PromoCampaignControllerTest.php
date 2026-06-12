<?php

namespace Tests\Feature\Hellom;

use App\Models\Organization;
use App\Models\PromoCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoCampaignControllerTest extends TestCase
{
    use RefreshDatabase;
    use HellomTestHelpers;

    private function createAdminWithToken(): array
    {
        return $this->createHellomUser(['role' => 'admin']);
    }

    // ─── Admin CRUD ───

    public function test_create_promo_campaign(): void
    {
        [$admin, $token] = $this->createAdminWithToken();

        $response = $this->postJson('/api/v1/hellom/admin/promos', [
            'code' => 'WELCOME50',
            'name' => 'Welcome Promo',
            'type' => 'percentage',
            'value' => 50,
            'max_slots' => 100,
            'is_active' => true,
        ], $this->hellomHeaders($token));

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('promo_campaigns', ['code' => 'WELCOME50']);
    }

    public function test_list_promo_campaigns(): void
    {
        [$admin, $token] = $this->createAdminWithToken();

        PromoCampaign::query()->create([
            'code' => 'TEST10',
            'name' => 'Test 10%',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/hellom/admin/promos', $this->hellomHeaders($token));

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_update_promo_campaign(): void
    {
        [$admin, $token] = $this->createAdminWithToken();

        $campaign = PromoCampaign::query()->create([
            'code' => 'UPD10',
            'name' => 'Update Me',
            'type' => 'fixed',
            'value' => 10000,
            'is_active' => true,
        ]);

        $response = $this->putJson("/api/v1/hellom/admin/promos/{$campaign->id}", [
            'name' => 'Updated Name',
            'value' => 20000,
        ], $this->hellomHeaders($token));

        $response->assertOk();
        $this->assertDatabaseHas('promo_campaigns', ['id' => $campaign->id, 'name' => 'Updated Name', 'value' => 20000]);
    }

    public function test_delete_promo_campaign(): void
    {
        [$admin, $token] = $this->createAdminWithToken();

        $campaign = PromoCampaign::query()->create([
            'code' => 'DEL99',
            'name' => 'Delete Me',
            'type' => 'fixed',
            'value' => 5000,
            'is_active' => true,
        ]);

        $response = $this->deleteJson("/api/v1/hellom/admin/promos/{$campaign->id}", [], $this->hellomHeaders($token));

        $response->assertOk();
        $this->assertDatabaseMissing('promo_campaigns', ['id' => $campaign->id]);
    }

    // ─── Promo Validate ───

    public function test_validate_valid_promo_code(): void
    {
        [$user, $token] = $this->createHellomUser();

        PromoCampaign::query()->create([
            'code' => 'SAVE20',
            'name' => 'Save 20%',
            'type' => 'percentage',
            'value' => 20,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/hellom/promo/validate', [
            'code' => 'SAVE20',
            'amount' => 100000,
        ], $this->hellomHeaders($token));

        $response->assertOk()
            ->assertJsonPath('data.discount_amount', 20000)
            ->assertJsonPath('data.final_amount', 80000);
    }

    public function test_validate_invalid_promo_code(): void
    {
        [$user, $token] = $this->createHellomUser();

        $response = $this->postJson('/api/v1/hellom/promo/validate', [
            'code' => 'NOEXIST',
            'amount' => 100000,
        ], $this->hellomHeaders($token));

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_promo_crud_forbidden_for_member(): void
    {
        [$user, $token] = $this->createHellomUser(['role' => 'member']);

        $response = $this->postJson('/api/v1/hellom/admin/promos', [
            'code' => 'HACK',
            'name' => 'Hack',
            'type' => 'fixed',
            'value' => 99999,
        ], $this->hellomHeaders($token));

        $response->assertStatus(403);
    }
}
