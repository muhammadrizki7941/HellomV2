<?php

namespace Tests\Feature\Hellom;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationControllerTest extends TestCase
{
    use RefreshDatabase;
    use HellomTestHelpers;

    public function test_list_organizations(): void
    {
        [$user, $token] = $this->createHellomUser();

        $org = Organization::query()->create([
            'name' => 'My Org',
            'slug' => 'my-org',
            'status' => 'active',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        $response = $this->getJson('/api/v1/hellom/organizations', $this->hellomHeaders($token));

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_create_organization(): void
    {
        [$user, $token] = $this->createHellomUser();

        $response = $this->postJson('/api/v1/hellom/organizations', [
            'name' => 'New Org',
        ], $this->hellomHeaders($token));

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('organizations', ['name' => 'New Org']);
    }

    public function test_switch_organization(): void
    {
        [$user, $token] = $this->createHellomUser();

        $orgA = Organization::query()->create(['name' => 'Org A', 'slug' => 'org-a', 'status' => 'active']);
        $orgB = Organization::query()->create(['name' => 'Org B', 'slug' => 'org-b', 'status' => 'active']);
        $orgA->users()->attach($user->id, ['role' => 'owner']);
        $orgB->users()->attach($user->id, ['role' => 'member']);
        $user->forceFill(['current_organization_id' => $orgA->id])->save();

        $response = $this->postJson('/api/v1/hellom/organizations/switch', [
            'organization_id' => $orgB->id,
        ], $this->hellomHeaders($token));

        $response->assertOk();
        $this->assertEquals($orgB->id, $user->fresh()->current_organization_id);
    }

    public function test_member_cannot_create_second_organization(): void
    {
        [$user, $token] = $this->createHellomUser();

        $existingOrg = Organization::query()->create([
            'name' => 'Org Awal',
            'slug' => 'org-awal',
            'status' => 'active',
        ]);
        $existingOrg->users()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_organization_id' => $existingOrg->id])->save();

        $response = $this->postJson('/api/v1/hellom/organizations', [
            'name' => 'Org Kedua',
        ], $this->hellomHeaders($token));

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'USER_ALREADY_HAS_ORGANIZATION');
    }
}
