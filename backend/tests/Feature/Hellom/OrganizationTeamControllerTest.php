<?php

namespace Tests\Feature\Hellom;

use App\Models\Organization;
use App\Models\OrganizationTeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTeamControllerTest extends TestCase
{
    use RefreshDatabase;
    use HellomTestHelpers;

    public function test_accept_invitation_is_blocked_when_user_already_belongs_to_another_organization(): void
    {
        [$user, $token] = $this->createHellomUser([
            'email' => 'member@example.com',
        ]);

        $existingOrg = Organization::query()->create([
            'name' => 'Org Lama',
            'slug' => 'org-lama',
            'status' => 'active',
        ]);
        $targetOrg = Organization::query()->create([
            'name' => 'Org Baru',
            'slug' => 'org-baru',
            'status' => 'active',
        ]);

        $existingOrg->users()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_organization_id' => $existingOrg->id])->save();

        $plainToken = str_repeat('a', 48);

        OrganizationTeamInvitation::query()->create([
            'organization_id' => $targetOrg->id,
            'email' => 'member@example.com',
            'role' => 'admin',
            'token_hash' => hash('sha256', $plainToken),
            'invited_by_user_id' => $user->id,
            'status' => OrganizationTeamInvitation::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->postJson('/api/v1/hellom/organizations/current/team/invitations/accept', [
            'token' => $plainToken,
        ], $this->hellomHeaders($token));

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'USER_HAS_OTHER_ORGANIZATION');

        $this->assertFalse($targetOrg->users()->where('users.id', $user->id)->exists());
    }

    public function test_invite_existing_user_from_other_organization_is_blocked(): void
    {
        [$owner, $token] = $this->createHellomUser([
            'email' => 'owner@example.com',
        ]);

        $invitee = User::factory()->create([
            'name' => 'Invitee',
            'email' => 'invitee@example.com',
            'password' => 'password',
            'role' => 'member',
        ]);

        $orgA = Organization::query()->create([
            'name' => 'Org A',
            'slug' => 'org-a',
            'status' => 'active',
        ]);
        $orgB = Organization::query()->create([
            'name' => 'Org B',
            'slug' => 'org-b',
            'status' => 'active',
        ]);

        $orgA->users()->attach($owner->id, ['role' => 'owner']);
        $owner->forceFill(['current_organization_id' => $orgA->id])->save();

        $orgB->users()->attach($invitee->id, ['role' => 'owner']);
        $invitee->forceFill(['current_organization_id' => $orgB->id])->save();

        $response = $this->postJson('/api/v1/hellom/organizations/current/team/invite', [
            'email' => 'invitee@example.com',
            'role' => 'admin',
        ], $this->hellomHeaders($token));

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'USER_HAS_OTHER_ORGANIZATION');

        $this->assertFalse($orgA->users()->where('users.id', $invitee->id)->exists());
    }
}
