<?php

namespace Tests\Landing;

use App\Jobs\SendPlatformMail;
use App\Models\ApiToken;
use App\Models\OwnerNotification;
use App\Models\SellerWithdrawal;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Hellom\PlatformMailService;
use App\Services\NotificationService;
use App\Services\Payments\DisbursementResult;
use App\Services\Payments\Gateways\XenditGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\SellerFinance\FinanceSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;

/** Every withdrawal waits for super admin approval; the owner is notified by email (official sender). */
class WithdrawalApprovalTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    private function superAdmin(string $email = null): string
    {
        $admin = User::query()->create(['name' => 'SA', 'email' => $email ?? Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'super_admin']);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $admin->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);

        return $plain;
    }

    public function test_auto_mode_still_waits_for_super_admin_approval_and_owner_is_emailed(): void
    {
        // A gateway that can send money: in "auto" mode it must only be used after approval.
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('supportsDisbursement')->andReturn(true);
        $gateway->shouldReceive('name')->andReturn('fake-payout');
        $gateway->shouldReceive('disburse')->once()->andReturn(new DisbursementResult(accepted: true, providerRef: 'payout-1'));
        $this->app->instance(XenditGateway::class, $gateway);
        app(FinanceSettings::class)->update(['withdrawal_mode' => 'auto']);
        SystemSetting::set(PlatformMailService::OWNER_EMAIL_SETTING, 'Owner.Pribadi@Gmail.test');

        $seller = $this->seller(100000);
        $this->paidSale($seller);
        Bus::fake([SendPlatformMail::class]);

        $id = $this->withHeader('Authorization', "Bearer {$seller['token']}")
            ->postJson('/api/v1/hellom/seller/finance/withdrawals', ['amount' => 50000])
            ->assertCreated()->assertJsonPath('data.withdrawal.status', 'requested')->json('data.withdrawal.id');
        $this->assertSame(SellerWithdrawal::STATUS_REQUESTED, SellerWithdrawal::query()->find($id)->status);

        // Super admin inbox + email to the owner's own address.
        $notification = OwnerNotification::query()->where('reference_type', 'seller_withdrawal')->where('reference_id', $id)->firstOrFail();
        $this->assertSame('Penarikan dana menunggu persetujuan', $notification->title);
        $this->assertSame('/admin/keuangan-penjual', $notification->action_url);
        Bus::assertDispatched(SendPlatformMail::class, fn (SendPlatformMail $job) => $job->to === ['owner.pribadi@gmail.test']
            && $job->subject === '[Hellom] Penarikan dana menunggu persetujuan' && str_contains((string) $job->payload['intro'], 'Rp 50.000'));
        // The seller is told it waits for approval.
        Bus::assertDispatched(SendPlatformMail::class, fn (SendPlatformMail $job) => str_starts_with($job->subject, 'Penarikan dana diajukan')
            && str_contains((string) $job->payload['intro'], 'disetujui tim Hellom'));

        // Only after approval does the gateway transfer it.
        $this->withHeader('Authorization', 'Bearer ' . $this->superAdmin())
            ->postJson("/api/v1/hellom/admin/seller-finance/withdrawals/{$id}/approve")->assertOk()->assertJsonPath('data.withdrawal.status', 'processing');
        $this->assertSame('payout-1', SellerWithdrawal::query()->find($id)->provider_ref);
    }

    public function test_owner_email_setting_validation_and_fallback_to_super_admins(): void
    {
        $token = $this->superAdmin('pemilik.akun@example.test');
        SystemSetting::set(PlatformMailService::OWNER_EMAIL_SETTING, '');
        $mail = app(PlatformMailService::class);
        $this->assertContains('pemilik.akun@example.test', $mail->ownerEmails()); // empty setting → super admin accounts

        $base = ['enabled' => false, 'host' => '', 'port' => 587, 'username' => '', 'encryption' => 'tls', 'from_address' => 'noreply@hellomspace.com', 'from_name' => 'Hellom'];
        $this->withHeader('Authorization', "Bearer {$token}")->putJson('/api/v1/hellom/admin/mail-settings', $base + ['owner_email' => 'bukan-email'])
            ->assertStatus(422)->assertJsonValidationErrors('owner_email');
        $this->withHeader('Authorization', "Bearer {$token}")->putJson('/api/v1/hellom/admin/mail-settings', $base + ['owner_email' => 'Owner@Gmail.test, kedua@yahoo.test'])
            ->assertOk()->assertJsonPath('data.mail.owner_email', 'owner@gmail.test, kedua@yahoo.test')
            ->assertJsonPath('data.mail.owner_email_effective', ['owner@gmail.test', 'kedua@yahoo.test']);
        $this->assertSame(['owner@gmail.test', 'kedua@yahoo.test'], $mail->ownerEmails());
    }

    public function test_every_super_admin_notification_is_copied_to_the_owner_email(): void
    {
        SystemSetting::set(PlatformMailService::OWNER_EMAIL_SETTING, 'owner@gmail.test');
        Bus::fake([SendPlatformMail::class]);
        $user = User::query()->create(['name' => 'Budi Baru', 'email' => Str::lower(Str::random(8)) . '@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'admin']);

        app(NotificationService::class)->createNewUserNotif($user, 'Hellom Page');

        // Goes to the configured owner (the old code sent it to a hard-coded admin@hellom.id).
        Bus::assertDispatched(SendPlatformMail::class, fn (SendPlatformMail $job) => $job->to === ['owner@gmail.test'] && $job->subject === '[Hellom] Pendaftar Baru'
            && str_contains((string) $job->payload['intro'], 'Budi Baru'));
        Bus::assertNotDispatched(SendPlatformMail::class, fn (SendPlatformMail $job) => in_array('admin@hellom.id', $job->to, true));
    }
}
