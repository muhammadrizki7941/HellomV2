<?php

namespace Tests\Pos;

use App\Mail\HellomPasswordResetMail;
use App\Mail\OrganizationTeamInvitationMail;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\OrganizationTeamInvitation;
use App\Models\PosStaff;
use App\Models\User;
use App\Services\Hellom\PlatformMailService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\NoopPlatformMailService;

/** Invitation page, forgot/reset password (incl. POS staff without an account). No real email. */
class AccountRecoveryTest extends PosTestCase
{
    use DatabaseTransactions;

    private NoopPlatformMailService $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new NoopPlatformMailService();
        $this->app->instance(PlatformMailService::class, $this->mail);
    }

    private function store(): array
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('R');
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        Entitlement::query()->create(['organization_id' => $org->id, 'app_id' => $app->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner-' . Str::lower(Str::random(6)) . '@example.test', 'password' => bcrypt('x-password'),
            'role' => 'admin', 'current_organization_id' => $org->id]);
        $org->users()->attach($owner->id, ['role' => 'owner']);

        return [$org, $outlet, $owner];
    }

    private function invite(Organization $org, User $owner, string $email, ?int $staffId): string
    {
        $plain = Str::random(48);
        OrganizationTeamInvitation::query()->create(['organization_id' => $org->id, 'email' => $email, 'role' => 'cashier', 'token_hash' => hash('sha256', $plain),
            'status' => 'pending', 'expires_at' => now()->addDays(7), 'pos_staff_id' => $staffId, 'invited_by_user_id' => $owner->id]);

        return $plain;
    }

    public function test_invitation_preview_and_joining_with_an_existing_account_that_owns_another_business(): void
    {
        [$org, $outlet, $owner] = $this->store();
        ['org' => $ownBusiness] = $this->makeOrganization('OWN');
        $user = User::query()->create(['name' => 'Sinta', 'email' => 'sinta-' . Str::lower(Str::random(5)) . '@example.test', 'password' => bcrypt('rahasia-123'),
            'role' => 'member', 'current_organization_id' => $ownBusiness->id]); // not a platform admin: the one-org rule applies
        $ownBusiness->users()->attach($user->id, ['role' => 'owner']);
        $staff = PosStaff::query()->create(['organization_id' => $org->id, 'outlet_id' => $outlet->id, 'tenant_id' => $outlet->tenant_slug,
            'name' => 'Sinta', 'email' => $user->email, 'role' => 'cashier', 'employment_status' => 'active']);
        $token = $this->invite($org, $owner, $user->email, $staff->id);

        $this->getJson("/api/v1/hellom/public/invitations/{$token}")->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.has_account', true)
            ->assertJsonPath('data.role_label', 'Kasir')->assertJsonPath('data.outlet_name', $outlet->name)
            ->assertJsonPath('data.organization_name', $org->name);
        $this->getJson('/api/v1/hellom/public/invitations/' . str_repeat('x', 40))->assertNotFound();

        // Log in, accept: allowed although she owns another business; she lands in the store's POS.
        $login = $this->postJson('/api/v1/hellom/auth/login', ['email' => $user->email, 'password' => 'rahasia-123'])->json('data.token');
        $h = ['Authorization' => "Bearer {$login}"];
        $this->withHeaders($h)->postJson('/api/v1/hellom/organizations/current/team/invitations/accept', ['token' => $token])->assertOk();
        $this->withHeaders($h)->getJson('/api/v1/hellom/auth/me')->assertJsonPath('data.current_organization.id', $org->id)
            ->assertJsonPath('data.pos_access.is_cashier', true);
        $this->assertSame($user->id, $staff->fresh()->linked_user_id);
        $this->getJson("/api/v1/hellom/public/invitations/{$token}")->assertJsonPath('data.status', 'accepted');
    }

    public function test_new_account_from_invitation_is_email_verified(): void
    {
        [$org, $outlet, $owner] = $this->store();
        $email = 'baru-' . Str::lower(Str::random(5)) . '@example.test';
        $staff = PosStaff::query()->create(['organization_id' => $org->id, 'outlet_id' => $outlet->id, 'tenant_id' => $outlet->tenant_slug,
            'name' => 'Baru', 'email' => $email, 'role' => 'cashier', 'employment_status' => 'active']);
        $token = $this->invite($org, $owner, $email, $staff->id);
        $this->getJson("/api/v1/hellom/public/invitations/{$token}")->assertJsonPath('data.has_account', false);

        $this->postJson('/api/v1/hellom/auth/register', ['name' => 'Baru', 'email' => $email, 'password' => 'rahasia-123', 'invite_token' => $token])
            ->assertCreated()->assertJsonPath('data.user.pos_access.is_cashier', true);
        $this->assertNotNull(User::query()->where('email', $email)->value('email_verified_at'));
    }

    public function test_forgot_password_sends_a_link_or_a_staff_activation_and_never_reveals_accounts(): void
    {
        [$org, $outlet] = $this->store();
        $user = User::query()->create(['name' => 'Dewi', 'email' => 'dewi-' . Str::lower(Str::random(5)) . '@example.test', 'password' => bcrypt('lama-sandi-1')]);
        $staffEmail = 'kasir-' . Str::lower(Str::random(5)) . '@example.test';
        $staff = PosStaff::query()->create(['organization_id' => $org->id, 'outlet_id' => $outlet->id, 'tenant_id' => $outlet->tenant_slug,
            'name' => 'Kasir Baru', 'email' => strtoupper($staffEmail), 'role' => 'cashier', 'employment_status' => 'active']);

        $answers = [];
        foreach ([$user->email, $staffEmail, 'tidak-ada@example.test'] as $email) {
            $answers[] = $this->postJson('/api/v1/hellom/auth/forgot-password', ['email' => $email])->assertOk()->json();
        }
        // Identical shape and message for all three (no account enumeration).
        $this->assertSame($answers[0]['message'], $answers[2]['message']);
        $this->assertSame(array_keys($answers[0]['data']), array_keys($answers[2]['data']));
        $this->assertArrayNotHasKey('email_delivery', $answers[0]['data']);

        $this->assertCount(2, $this->mail->sent);
        $reset = $this->mail->sent[0]['mailable'];
        $this->assertInstanceOf(HellomPasswordResetMail::class, $reset);
        $this->assertStringContainsString('/reset-password?token=', (string) $reset->resetUrl);
        $activation = $this->mail->sent[1]['mailable'];
        $this->assertInstanceOf(OrganizationTeamInvitationMail::class, $activation);
        $this->assertTrue($activation->activation);
        $this->assertStringContainsString('/invitation/accept?token=', (string) $activation->registerUrl);
        $this->assertSame(1, OrganizationTeamInvitation::query()->where('pos_staff_id', $staff->id)->where('status', 'pending')->count());

        // Reset with the emailed token: new password works, email becomes verified, old tokens die.
        $this->postJson('/api/v1/hellom/auth/reset-password', ['email' => $user->email, 'token' => $reset->token, 'password' => 'baru-sandi-9', 'password_confirmation' => 'baru-sandi-9'])
            ->assertOk()->assertJsonPath('data.next', '/login');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->postJson('/api/v1/hellom/auth/login', ['email' => $user->email, 'password' => 'baru-sandi-9'])->assertOk();
        $this->postJson('/api/v1/hellom/auth/reset-password', ['email' => $user->email, 'token' => $reset->token, 'password' => 'lagi-sandi-9', 'password_confirmation' => 'lagi-sandi-9'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_FAILED');
    }

    public function test_reset_for_pos_staff_points_to_the_cashier_login(): void
    {
        [$org, $outlet] = $this->store();
        $user = User::query()->create(['name' => 'Rani', 'email' => 'rani-' . Str::lower(Str::random(5)) . '@example.test', 'password' => bcrypt('lama-sandi-1')]);
        PosStaff::query()->create(['organization_id' => $org->id, 'outlet_id' => $outlet->id, 'tenant_id' => $outlet->tenant_slug, 'linked_user_id' => $user->id,
            'name' => 'Rani', 'role' => 'cashier', 'employment_status' => 'active']);
        $this->postJson('/api/v1/hellom/auth/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->mail->sent[0]['mailable']->token;
        $this->postJson('/api/v1/hellom/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'baru-sandi-9', 'password_confirmation' => 'baru-sandi-9'])
            ->assertOk()->assertJsonPath('data.next', '/login/kasir');
    }
}
