<?php

namespace Tests\Landing;

use App\Models\AppCatalog;
use App\Models\CheckoutIntent;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Organization;
use App\Models\PromoCampaign;
use App\Models\SubscriptionCheckoutPayment;
use App\Models\User;
use App\Services\Hellom\IpaymuSettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PosGuestCheckoutTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    public function test_guest_checkout_uses_server_price_and_limits_status_access_to_its_token(): void
    {
        $plan = $this->posPlan(52000);
        $this->fakeDirectPayments(['trx-guest-price']);

        $response = $this->startGuest($plan, [
            'amount' => 1,
            'expected_amount' => 1,
        ])->assertCreated();

        $token = (string) $response->json('data.status_token');
        $intent = CheckoutIntent::query()->where('status_token_hash', hash('sha256', $token))->firstOrFail();
        $invoice = Invoice::query()->where('subscription_id', $intent->subscription_id)->firstOrFail();
        $this->assertSame(52000, (int) $intent->amount);
        $this->assertSame(52000, (int) $invoice->total);
        $this->assertSame('pos', (string) $intent->app->slug);
        $this->assertSame(48, strlen($token));

        $this->getJson('/api/v1/hellom/public/pos-checkout/' . $token)
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 52000);
        $this->getJson('/api/v1/hellom/public/pos-checkout/' . Str::random(48))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'CHECKOUT_NOT_FOUND');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v2/payment/direct')
            && (int) $request['amount'] === 52000);
    }

    public function test_public_promo_preview_accepts_valid_code_and_rejects_invalid_code(): void
    {
        $plan = $this->posPlan(48000);
        $plan->update(['billing_cycles' => ['yearly']]);
        $app = AppCatalog::query()->where('slug', 'pos')->firstOrFail();
        PromoCampaign::query()->create([
            'code' => 'POS25' . random_int(10, 99),
            'name' => 'Promo POS',
            'type' => 'percentage',
            'value' => 25,
            'max_slots' => 10,
            'used_slots' => 0,
            'app_id' => $app->id,
            'plan_id' => $plan->id,
            'is_active' => true,
        ]);
        $code = (string) PromoCampaign::query()->where('plan_id', $plan->id)->value('code');

        $this->postJson('/api/v1/hellom/public/pos-checkout/promo', [
            'plan_slug' => $plan->slug,
            'code' => $code,
        ])->assertOk()
            ->assertJsonPath('data.discount_amount', 12000)
            ->assertJsonPath('data.final_amount', 36000);

        $this->postJson('/api/v1/hellom/public/pos-checkout/promo', [
            'plan_slug' => $plan->slug,
            'code' => 'NOT-A-REAL-PROMO',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'PROMO_INVALID');
    }

    public function test_guest_account_cannot_be_duplicated_when_email_is_already_active(): void
    {
        $plan = $this->posPlan();
        $this->fakeDirectPayments(['trx-duplicate']);
        User::query()->create([
            'name' => 'Akun Aktif',
            'email' => 'aktif@example.test',
            'password' => bcrypt(Str::random(20)),
            'role' => 'member',
        ]);

        $this->startGuest($plan, ['email' => 'aktif@example.test'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LOGIN_REQUIRED');
        $this->assertSame(1, User::query()->where('email', 'aktif@example.test')->count());
        Http::assertNothingSent();
    }

    public function test_verified_callback_is_idempotent_and_duplicate_paid_attempt_does_not_activate_twice(): void
    {
        $plan = $this->posPlan();
        $this->fakeDirectPayments(['trx-first', 'trx-second']);
        $first = $this->startGuest($plan)->assertCreated();
        $statusToken = (string) $first->json('data.status_token');
        $intent = CheckoutIntent::query()->where('status_token_hash', hash('sha256', $statusToken))->firstOrFail();
        $firstAttempt = $intent->payments()->firstOrFail();

        $this->postJson('/api/v1/hellom/public/pos-checkout/' . $statusToken . '/payment', [
            'payment_channel' => 'bca',
        ])->assertCreated();
        $secondAttempt = $intent->payments()->latest('id')->firstOrFail();
        $this->assertSame('replaced', $firstAttempt->fresh()->status);
        $this->assertNotSame($firstAttempt->transaction_id, $secondAttempt->transaction_id);

        $this->fakeIpaymuTransaction(
            (string) $secondAttempt->transaction_id,
            (string) $secondAttempt->reference_id,
            (int) $secondAttempt->amount,
        );
        $callback = $this->callbackFor($secondAttempt);
        $this->postJson('/api/v1/hellom/public/pos-checkout/ipaymu/callback', $callback['body'], [
            'X-Signature' => $callback['signature'],
        ])->assertOk()->assertJsonPath('status', 'ok');

        $this->postJson('/api/v1/hellom/public/pos-checkout/ipaymu/callback', $callback['body'], [
            'X-Signature' => $callback['signature'],
        ])->assertOk()->assertJsonPath('status', 'ok');
        $this->assertSame('confirmed', $intent->fresh()->status);
        $this->assertSame('active', Organization::query()->findOrFail($intent->organization_id)->status);
        $this->assertSame('paid', $secondAttempt->fresh()->status);

        $this->fakeIpaymuTransaction(
            (string) $firstAttempt->transaction_id,
            (string) $firstAttempt->reference_id,
            (int) $firstAttempt->amount,
        );
        $firstCallback = $this->callbackFor($firstAttempt);
        $this->postJson('/api/v1/hellom/public/pos-checkout/ipaymu/callback', $firstCallback['body'], [
            'X-Signature' => $firstCallback['signature'],
        ])->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame('duplicate_paid', $firstAttempt->fresh()->status);
        $this->assertSame(1, \App\Models\Entitlement::query()
            ->where('organization_id', $intent->organization_id)
            ->where('app_id', $intent->app_id)
            ->count());
    }

    public function test_options_include_yearly_and_lifetime_but_hide_unavailable_plans(): void
    {
        $yearly = $this->posPlan(48000);
        $yearly->update(['billing_cycles' => ['yearly']]);
        $lifetime = $this->posPlan();
        $lifetime->update(['type' => 'lifetime', 'billing_cycles' => []]);
        $hidden = $this->posPlan();
        $hidden->update(['is_visible' => false]);
        $inactive = $this->posPlan();
        $inactive->update(['is_active' => false]);
        $plans = collect($this->getJson('/api/v1/hellom/public/pos-checkout/options')->assertOk()->json('data.plans'));
        $this->assertSame('yearly', $plans->firstWhere('id', $yearly->id)['billing_cycle']);
        $this->assertSame(48000, $plans->firstWhere('id', $yearly->id)['price']);
        $this->assertSame(['yearly'], $plans->firstWhere('id', $yearly->id)['billing_cycles']);
        $this->assertSame('lifetime', $plans->firstWhere('id', $lifetime->id)['billing_cycle']);
        $this->assertFalse($plans->contains('id', $hidden->id));
        $this->assertFalse($plans->contains('id', $inactive->id));
    }

    public function test_yearly_guest_checkout_preserves_price_and_period_through_paid_activation(): void
    {
        $plan = $this->posPlan(48000);
        $plan->update(['billing_cycles' => ['yearly']]);
        $this->fakeDirectPayments(['trx-yearly']);
        $response = $this->startGuest($plan)->assertCreated();
        $intent = CheckoutIntent::query()->where('status_token_hash', hash('sha256', $response->json('data.status_token')))->firstOrFail();
        $this->assertSame('yearly', $intent->subscription->billing_cycle);
        $this->assertSame('yearly', $intent->metadata['billing_cycle']);
        $invoice = Invoice::query()->where('subscription_id', $intent->subscription_id)->firstOrFail();
        $this->assertSame('yearly', $invoice->metadata['billing_cycle']);
        $this->assertSame(48000, (int) $invoice->total);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v2/payment/direct') && (int) $request['amount'] === 48000);
        $attempt = $intent->payments()->firstOrFail();
        $this->fakeIpaymuTransaction($attempt->transaction_id, $attempt->reference_id, 48000);
        $callback = $this->callbackFor($attempt);
        $this->postJson('/api/v1/hellom/public/pos-checkout/ipaymu/callback', $callback['body'], ['X-Signature' => $callback['signature']])->assertOk();
        $subscription = $intent->subscription->fresh();
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->ends_at->equalTo($subscription->starts_at->copy()->addYear()));
    }

    public function test_guest_can_select_yearly_on_dual_cycle_plan_and_rejects_unavailable_cycle(): void
    {
        $plan = $this->posPlan();
        $this->fakeDirectPayments(['trx-dual']);
        $this->startGuest($plan, ['billing_cycle' => 'yearly'])->assertUnprocessable();
        Http::assertNothingSent();
        $plan->update(['billing_cycles' => ['monthly', 'yearly']]);
        $response = $this->startGuest($plan, ['billing_cycle' => 'yearly'])->assertCreated();
        $intent = CheckoutIntent::query()->where('status_token_hash', hash('sha256', $response->json('data.status_token')))->firstOrFail();
        $this->assertSame('yearly', $intent->subscription->billing_cycle);
    }

    private function posPlan(int $price = 35000): Plan
    {
        $app = AppCatalog::query()->updateOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        app(IpaymuSettingsService::class)->saveConfig(['direct_channels' => ['qris', 'bca']]);
        return Plan::query()->create([
            'slug' => 'pos_guest_' . Str::lower(Str::random(8)),
            'name' => 'POS Guest',
            'type' => 'subscription',
            'price' => $price,
            'is_active' => true,
            'is_visible' => true,
            'billing_cycles' => ['monthly'],
            'features' => ['Kasir dan laporan'],
        ]);
    }

    private function startGuest(Plan $plan, array $overrides = [])
    {
        return $this->postJson('/api/v1/hellom/public/pos-checkout', array_merge([
            'name' => 'Pemilik Usaha',
            'email' => Str::lower(Str::random(12)) . '@example.test',
            'phone' => '081234567890',
            'business_name' => 'Toko ' . Str::random(5),
            'plan_slug' => $plan->slug,
            'payment_channel' => 'qris',
            'terms_accepted' => true,
            'website' => '',
        ], $overrides));
    }

    /** @param list<string> $transactionIds */
    private function fakeDirectPayments(array $transactionIds): void
    {
        $index = 0;
        Http::fake([
            '*/api/v2/payment/direct' => function () use (&$index, $transactionIds) {
                $transactionId = $transactionIds[$index] ?? 'trx-pos-' . $index;
                $index++;
                return Http::response(['Status' => 200, 'Data' => [
                    'TransactionId' => $transactionId,
                    'PaymentNo' => '880812345678',
                    'QrString' => 'hellom-qris-test',
                ]]);
            },
            '*/api/v2/transaction' => function (\Illuminate\Http\Client\Request $request) {
                return Http::response(['Status' => 200, 'Data' => [
                    'TransactionId' => (string) $request['transactionId'],
                    'Amount' => 35000,
                    'Status' => 0,
                ]]);
            },
        ]);
    }

    /** @return array{body:array<string,mixed>,signature:string} */
    private function callbackFor(SubscriptionCheckoutPayment $payment): array
    {
        $body = [
            'trx_id' => (string) $payment->transaction_id,
            'reference_id' => (string) $payment->reference_id,
            'status_code' => 1,
            'transaction_status_code' => 1,
            'paid_off' => true,
            'is_escrow' => false,
            'additional_info' => [],
        ];
        return [
            'body' => $body,
            'signature' => app(\App\Services\Payments\IpaymuCallbackSignature::class)
                ->sign($body, (string) app(IpaymuSettingsService::class)->getConfig()['va']),
        ];
    }
}
