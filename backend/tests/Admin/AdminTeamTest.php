<?php

namespace Tests\Admin;

use App\Jobs\SendPlatformMail;
use App\Models\AuditLog;
use App\Models\OwnerNotification;
use App\Models\PlatformAdminInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

/** Super admin › Tim admin: invite by email (password confirmed), accept, remove, safety rules. */
class AdminTeamTest extends AdminTestCase
{
    private function inviteToken(): string
    {
        $job = null;
        Bus::assertDispatched(SendPlatformMail::class, function (SendPlatformMail $j) use (&$job) {
            if ($j->subject === 'Undangan menjadi admin Hellom') {
                $job = $j;
            }

            return true;
        });
        parse_str((string) parse_url((string) $job->payload['cta_url'], PHP_URL_QUERY), $query);

        return (string) $query['token'];
    }

    public function test_invite_needs_password_and_super_admin(): void
    {
        Bus::fake([SendPlatformMail::class]);
        $admin = $this->superAdmin();
        $owner = $this->makeUser('admin');

        $this->api($owner, 'POST', '/admin/team/invitations', ['email' => 'baru@example.test', 'password' => 'rahasia-123'])->assertForbidden();
        $this->api($admin, 'POST', '/admin/team/invitations', ['email' => 'baru@example.test', 'password' => 'salah'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->api($admin, 'POST', '/admin/team/invitations', ['email' => 'Baru@Example.test', 'password' => 'rahasia-123'])
            ->assertOk()->assertJsonPath('data.invitations.0.email', 'baru@example.test')->assertJsonPath('data.invitations.0.status', 'pending');
        $this->assertTrue(AuditLog::query()->where('action', 'admin_team.invited')->where('user_id', $admin->id)->exists());
        $this->api($admin, 'POST', '/admin/team/invitations', ['email' => $admin->email, 'password' => 'rahasia-123'])
            ->assertStatus(422)->assertJsonPath('error.code', 'ALREADY_ADMIN');
    }

    public function test_new_person_accepts_with_name_and_password_and_can_log_in(): void
    {
        Bus::fake([SendPlatformMail::class]);
        $admin = $this->superAdmin();
        $this->api($admin, 'POST', '/admin/team/invitations', ['email' => 'rekan@example.test', 'password' => 'rahasia-123'])->assertOk();
        $token = $this->inviteToken();

        $this->getJson("/api/v1/hellom/public/admin-invitations/{$token}")->assertOk()
            ->assertJsonPath('data.email', 'rekan@example.test')->assertJsonPath('data.has_account', false)->assertJsonPath('data.status', 'pending');
        $this->postJson("/api/v1/hellom/public/admin-invitations/{$token}/accept", ['name' => 'Rekan', 'password' => 'pendek', 'password_confirmation' => 'pendek'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson("/api/v1/hellom/public/admin-invitations/{$token}/accept", ['name' => 'Rekan Admin', 'password' => 'admin-baru-123', 'password_confirmation' => 'admin-baru-123'])
            ->assertOk()->assertJsonPath('data.role', 'super_admin');

        $user = User::query()->where('email', 'rekan@example.test')->firstOrFail();
        $this->assertSame('super_admin', $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->postJson('/api/v1/hellom/auth/login', ['email' => 'rekan@example.test', 'password' => 'admin-baru-123'])->assertOk()->assertJsonPath('data.user.role', 'super_admin');
        // The link works once; the owner is told.
        $this->postJson("/api/v1/hellom/public/admin-invitations/{$token}/accept", ['password' => 'admin-baru-123'])->assertStatus(422)->assertJsonPath('error.code', 'INVITATION_INVALID');
        $this->assertTrue(OwnerNotification::query()->where('title', 'Admin baru bergabung')->exists());
    }

    public function test_existing_account_confirms_with_its_own_password_and_expired_links_fail(): void
    {
        Bus::fake([SendPlatformMail::class]);
        $admin = $this->superAdmin();
        $shopOwner = $this->makeUser('admin');
        $this->api($admin, 'POST', '/admin/team/invitations', ['email' => $shopOwner->email, 'password' => 'rahasia-123'])->assertOk();
        $token = $this->inviteToken();

        $this->getJson("/api/v1/hellom/public/admin-invitations/{$token}")->assertJsonPath('data.has_account', true);
        $this->postJson("/api/v1/hellom/public/admin-invitations/{$token}/accept", ['password' => 'bukan-ini'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PASSWORD');
        $this->assertSame('admin', $shopOwner->fresh()->role);
        $this->postJson("/api/v1/hellom/public/admin-invitations/{$token}/accept", ['password' => 'rahasia-123'])->assertOk();
        $this->assertSame('super_admin', $shopOwner->fresh()->role);

        // Expired invitation.
        $this->api($admin, 'POST', '/admin/team/invitations', ['email' => 'telat@example.test', 'password' => 'rahasia-123'])->assertOk();
        PlatformAdminInvitation::query()->where('email', 'telat@example.test')->update(['expires_at' => now()->subMinute()]);
        Bus::fake([SendPlatformMail::class]);
        $this->api($admin, 'POST', '/admin/team/invitations', ['email' => 'telat2@example.test', 'password' => 'rahasia-123'])->assertOk();
        $fresh = $this->inviteToken();
        PlatformAdminInvitation::query()->where('token_hash', hash('sha256', $fresh))->update(['expires_at' => now()->subMinute()]);
        $this->getJson("/api/v1/hellom/public/admin-invitations/{$fresh}")->assertJsonPath('data.status', 'expired');
        $this->postJson("/api/v1/hellom/public/admin-invitations/{$fresh}/accept", ['name' => 'X', 'password' => 'admin-baru-123', 'password_confirmation' => 'admin-baru-123'])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVITATION_INVALID');
        $this->getJson('/api/v1/hellom/public/admin-invitations/' . str_repeat('a', 48))->assertNotFound();
    }

    public function test_remove_admin_rules(): void
    {
        Bus::fake([SendPlatformMail::class]);
        $admin = $this->superAdmin();
        $other = $this->superAdmin();

        $this->api($admin, 'POST', "/admin/team/{$admin->id}/remove", ['password' => 'rahasia-123'])->assertStatus(422)->assertJsonPath('error.code', 'CANNOT_REMOVE_SELF');
        $this->api($admin, 'POST', "/admin/team/{$other->id}/remove", ['password' => 'salah'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->api($admin, 'POST', "/admin/team/{$other->id}/remove", ['password' => 'rahasia-123'])->assertOk();
        $this->assertSame('admin', $other->fresh()->role); // account kept, normal access only
        $this->api($other, 'GET', '/admin/team')->assertForbidden();
        $this->assertTrue(AuditLog::query()->where('action', 'admin_team.removed')->where('entity_id', $other->id)->exists());
        Bus::assertDispatched(SendPlatformMail::class, fn (SendPlatformMail $j) => $j->to === [$other->email] && $j->subject === 'Akses admin Hellom kamu dicabut');
    }
}
