<?php

namespace Tests\Landing;

use App\Mail\DigitalProductAccessMail;
use App\Mail\HellomCheckoutStatusMail;
use App\Models\DigitalProduct;
use App\Models\ProductPurchase;
use App\Models\User;
use App\Services\DigitalProducts\ProductAccessMailer;
use App\Services\Hellom\PlatformMailService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Pos\PosTestCase;
use Tests\Support\NoopPlatformMailService;

/**
 * Account made at guest checkout: the access email keeps the default password AND offers
 * "Buat kata sandi sendiri" (7-day, single-use token in password_setup_tokens). Using it
 * replaces the default password; normal reset tokens are not affected.
 */
class GuestPasswordSetupTest extends PosTestCase
{
    use DatabaseTransactions;

    private NoopPlatformMailService $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new NoopPlatformMailService();
        $this->app->instance(PlatformMailService::class, $this->mail);
    }

    private function paidGuestPurchase(): array
    {
        $user = User::query()->create(['name' => 'Budi', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt(Str::random(20)), 'role' => 'member']);
        $user->forceFill(['pending_guest_credentials' => true])->save();
        $product = DigitalProduct::query()->create(['slug' => 'ebook-' . Str::lower(Str::random(6)), 'name' => 'Ebook Resep', 'category' => 'ebook', 'type' => 'paid',
            'price' => 50000, 'currency' => 'IDR', 'is_published' => true]);
        $purchase = ProductPurchase::query()->create(['user_id' => $user->id, 'product_id' => $product->id, 'transaction_code' => 'PUR-' . Str::upper(Str::random(8)),
            'amount_paid' => 50000, 'payment_method' => 'qris', 'payment_status' => 'paid', 'payment_gateway' => 'ipaymu', 'paid_at' => now()]);
        $purchase->forceFill(['guest_token_hash' => hash('sha256', Str::random(20))])->save();

        return [$user, $purchase];
    }

    public function test_access_email_keeps_default_password_and_offers_own_password_link(): void
    {
        [$user, $purchase] = $this->paidGuestPurchase(); // ProductPurchaseObserver sends the access email
        $this->assertFalse(app(ProductAccessMailer::class)->sendForPaidPurchase($purchase)); // never twice
        $this->assertCount(1, array_filter($this->mail->sent, fn ($m) => $m['mailable'] instanceof DigitalProductAccessMail));

        /** @var DigitalProductAccessMail $mail */
        $mail = collect($this->mail->sent)->first(fn ($m) => $m['mailable'] instanceof DigitalProductAccessMail)['mailable'];
        $this->assertNotNull($mail->password);
        $this->assertTrue(Hash::check($mail->password, (string) $user->fresh()->password)); // default password works
        $this->assertStringContainsString('/reset-password?', (string) $mail->setPasswordUrl);
        $this->assertStringContainsString('setup=1', (string) $mail->setPasswordUrl);
        $html = $mail->render();
        $this->assertStringContainsString($mail->password, $html);
        $this->assertStringContainsString('Buat kata sandi sendiri', $html);
        $this->assertStringContainsString('kata sandi di atas tetap bisa dipakai', $html);

        // Link opened 3 days later (a normal reset link would be dead after 60 minutes).
        parse_str((string) parse_url($mail->setPasswordUrl, PHP_URL_QUERY), $query);
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $user->email)->count()); // separate table
        $this->travel(3)->days();

        $this->postJson('/api/v1/hellom/auth/reset-password', ['email' => $query['email'], 'token' => $query['token'], 'password' => 'pilihanku-123',
            'password_confirmation' => 'pilihanku-123'])->assertStatus(422); // without setup=1 the normal broker rejects it
        $this->postJson('/api/v1/hellom/auth/reset-password', ['email' => $query['email'], 'token' => $query['token'], 'setup' => 1,
            'password' => 'pilihanku-123', 'password_confirmation' => 'pilihanku-123'])->assertOk();

        $this->assertTrue(Hash::check('pilihanku-123', (string) $user->fresh()->password));
        $this->assertFalse(Hash::check($mail->password, (string) $user->fresh()->password)); // default no longer valid
        $security = collect($this->mail->sent)->last()['mailable'];
        $this->assertInstanceOf(HellomCheckoutStatusMail::class, $security);
        $this->assertStringContainsString('Buat kata sandi sendiri', $security->payload['intro']);

        // Single use.
        $this->postJson('/api/v1/hellom/auth/reset-password', ['email' => $query['email'], 'token' => $query['token'], 'setup' => 1,
            'password' => 'lain-lagi-456', 'password_confirmation' => 'lain-lagi-456'])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'Kata sandi dari email tetap bisa dipakai'));
    }

    public function test_setup_link_expires_after_seven_days_and_existing_accounts_get_none(): void
    {
        [$user, $purchase] = $this->paidGuestPurchase();
        app(ProductAccessMailer::class)->sendForPaidPurchase($purchase);
        $mail = collect($this->mail->sent)->first(fn ($m) => $m['mailable'] instanceof DigitalProductAccessMail)['mailable'];
        parse_str((string) parse_url($mail->setPasswordUrl, PHP_URL_QUERY), $query);

        $this->travel(8)->days();
        $this->postJson('/api/v1/hellom/auth/reset-password', ['email' => $query['email'], 'token' => $query['token'], 'setup' => 1,
            'password' => 'pilihanku-123', 'password_confirmation' => 'pilihanku-123'])->assertStatus(422);
        $this->assertTrue(Hash::check($mail->password, (string) $user->fresh()->password)); // default still works

        // Resend for an account that already has credentials: no password, no setup link.
        $this->mail->sent = [];
        app(ProductAccessMailer::class)->resend($purchase->fresh());
        $again = collect($this->mail->sent)->first(fn ($m) => $m['mailable'] instanceof DigitalProductAccessMail)['mailable'];
        $this->assertNull($again->password);
        $this->assertNull($again->setPasswordUrl);
    }
}
