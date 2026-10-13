<?php

namespace Tests\Landing;

use App\Mail\HellomCheckoutStatusMail;
use App\Mail\HellomWelcomeMail;
use App\Models\ApiToken;
use App\Models\AppCatalog;
use App\Models\ConsumerNotification;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Hellom\PlatformMailService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Pos\PosTestCase;
use Tests\Support\NoopPlatformMailService;

/**
 * SaaS account emails: welcome with first steps + verify button, verification reminder after 24 h
 * (once), "kata sandi diganti" after a change or reset, day-2 tips only while the shop page is not
 * published (info & tips category), plan ending in 7/3/1 days and ended → shop owners (email + bell).
 */
class LifecycleEmailsTest extends PosTestCase
{
    use DatabaseTransactions;

    private NoopPlatformMailService $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new NoopPlatformMailService();
        $this->app->instance(PlatformMailService::class, $this->mail);
    }

    /** @return list<array{to: string|array, mailable: \Illuminate\Contracts\Mail\Mailable}> */
    private function mailsTo(string $email): array
    {
        return array_values(array_filter($this->mail->sent, fn ($m) => in_array(strtolower($email), array_map('strtolower', (array) $m['to']), true)));
    }

    private function subjects(string $email): array
    {
        return array_map(fn ($m) => $m['mailable'] instanceof HellomCheckoutStatusMail ? $m['mailable']->subjectLine : class_basename($m['mailable']), $this->mailsTo($email));
    }

    private function owner(array $attributes = []): User
    {
        $user = User::query()->create($attributes + ['name' => 'Rina', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('rahasia123'), 'role' => 'admin']);
        if (array_key_exists('email_verified_at', $attributes)) { // not mass assignable
            $user->forceFill(['email_verified_at' => $attributes['email_verified_at']])->saveQuietly();
        }

        return $user;
    }

    public function test_sign_up_sends_welcome_with_first_steps_and_verify_button(): void
    {
        $email = Str::lower(Str::random(10)) . '@example.test';
        $this->postJson('/api/v1/hellom/auth/register', ['name' => 'Rina', 'email' => $email, 'password' => 'rahasia123', 'organization_name' => 'Kopi Rina ' . Str::random(4)])
            ->assertCreated();

        $welcome = $this->mailsTo($email)[0]['mailable'] ?? null;
        $this->assertInstanceOf(HellomWelcomeMail::class, $welcome);
        $this->assertStringContainsString('/public/email/verify/', (string) $welcome->verifyUrl);
        $html = $welcome->render();
        $this->assertStringContainsString('Verifikasi Email', $html);
        $this->assertStringContainsString('Kalkulator HPP', $html);
        $this->assertStringContainsString('Buat Hellom Page', $html);
    }

    public function test_password_change_and_reset_send_a_security_email(): void
    {
        $user = $this->owner();
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);

        $this->withHeader('Authorization', "Bearer {$plain}")->postJson('/api/v1/hellom/auth/change-password', [
            'current_password' => 'rahasia123', 'password' => 'baru-rahasia-1', 'password_confirmation' => 'baru-rahasia-1',
        ])->assertOk();
        $this->assertSame(['Kata sandi akun Hellom kamu diganti'], $this->subjects($user->email));
        $payload = $this->mailsTo($user->email)[0]['mailable']->payload;
        $this->assertStringContainsString('pengaturan akun', $payload['intro']);
        $this->assertStringEndsWith('/forgot-password', $payload['cta_url']);

        $token = \Illuminate\Support\Facades\Password::broker()->createToken($user->fresh());
        $this->postJson('/api/v1/hellom/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'lebih-baru-2', 'password_confirmation' => 'lebih-baru-2'])->assertOk();
        $this->assertCount(2, $this->mailsTo($user->email));
        $this->assertStringContainsString('lupa kata sandi', $this->mailsTo($user->email)[1]['mailable']->payload['intro']);
    }

    public function test_verification_reminder_and_day_two_tips_go_out_once_to_recent_sign_ups(): void
    {
        $unverified = $this->owner();
        $unverified->forceFill(['created_at' => now()->subHours(30)])->saveQuietly();
        $old = $this->owner(); // signed up long ago: never mailed by this job
        $old->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();
        $guest = $this->owner(['role' => 'member']);
        $guest->forceFill(['created_at' => now()->subHours(30), 'pending_guest_credentials' => true])->saveQuietly();

        $tipsUser = $this->owner(['email_verified_at' => now()]);
        $tipsUser->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
        $published = $this->owner(['email_verified_at' => now()]);
        $published->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
        ['org' => $org] = $this->makeOrganization('Pub');
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $published->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_landing_pages')->insert(['organization_id' => $org->id, 'title' => 'Toko', 'slug' => 'toko-' . Str::random(6), 'status' => 'published',
            'published_version_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('users:lifecycle-emails')->assertSuccessful();
        $this->artisan('users:lifecycle-emails')->assertSuccessful(); // second run: nothing new

        $this->assertSame(['Satu langkah lagi: verifikasi email Hellom kamu'], $this->subjects($unverified->email));
        $this->assertSame([], $this->subjects($old->email));
        $this->assertSame([], $this->subjects($guest->email));
        $this->assertSame(['3 langkah pertama di Hellom'], $this->subjects($tipsUser->email));
        $this->assertSame(PlatformMailService::CATEGORY_MARKETING, PlatformMailService::categoryOf($this->mailsTo($tipsUser->email)[0]['mailable']));
        $this->assertSame([], $this->subjects($published->email)); // already live → no tips
        $this->assertArrayHasKey('verify_reminder', $unverified->fresh()->lifecycle_emails);
    }

    public function test_plan_ending_and_ended_reach_the_shop_owner(): void
    {
        $owner = $this->owner(['email_verified_at' => now()]);
        ['org' => $org] = $this->makeOrganization('Langganan');
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'landing_builder'], ['name' => 'Hellom Page', 'is_active' => true]);
        $plan = Plan::query()->create(['slug' => 'tes-tahunan-' . Str::random(5), 'name' => 'Tahunan', 'type' => 'subscription', 'price' => 290000, 'is_active' => true, 'is_visible' => false]);
        $subscription = Subscription::query()->create(['organization_id' => $org->id, 'app_id' => $app->id, 'plan_id' => $plan->id, 'status' => 'active',
            'amount' => 290000, 'currency' => 'IDR', 'billing_cycle' => 'yearly', 'starts_at' => now()->subYear(), 'ends_at' => now()->addDays(3)->setTime(12, 0)]);

        $this->artisan('notifications:check-expiry')->assertSuccessful();
        $this->artisan('notifications:check-expiry')->assertSuccessful();

        $this->assertSame(['Langganan ' . $app->name . ' berakhir 3 hari lagi'], $this->subjects($owner->email));
        $bell = ConsumerNotification::query()->where('user_id', $owner->id)->where('type', 'expiry')->get();
        $this->assertCount(1, $bell);
        $this->assertSame('/dashboard/billing', $bell[0]->action_url);

        // Period over → expired notice.
        $subscription->forceFill(['ends_at' => now()->subDay()])->save();
        $this->artisan('hellom:billing:expire-subscriptions')->assertSuccessful();
        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertContains('Langganan ' . $app->name . ' sudah berakhir', $this->subjects($owner->email));
        $this->assertSame(2, ConsumerNotification::query()->where('user_id', $owner->id)->where('type', 'expiry')->count());
    }
}
