<?php

namespace Tests\Landing;

use App\Models\AppCatalog;
use App\Models\CheckoutIntent;
use App\Models\Invoice;
use App\Models\Plan;
use App\Mail\HellomCheckoutStatusMail;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Hellom\PaymentGatewaySettingsService;
use App\Services\Payments\IpaymuPaymentVerifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\NoopPlatformMailService;

class SubscriptionCheckoutPriceTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    public function test_admin_package_price_matches_invoice_and_hosted_gateway(): void
    {
        $this->checkout(false);
    }

    public function test_direct_checkout_returns_instructions_and_keeps_the_same_price(): void
    {
        $this->checkout(true);
    }

    public function test_qris_checkout_can_be_resumed_from_history(): void
    {
        $this->checkout(true, 'qris');
    }

    public function test_direct_checkout_emails_gateway_instructions_and_payment_status_link(): void
    {
        $seller = $this->seller();
        $mail = new class extends NoopPlatformMailService
        {
            /** @var list<array{to:string|array,mailable:Mailable}> */
            public array $queued = [];

            public function queue(string|array $to, Mailable $mailable): void
            {
                $this->queued[] = ['to' => $to, 'mailable' => $mailable];
            }
        };
        $this->app->instance(\App\Services\Hellom\PlatformMailService::class, $mail);
        AppCatalog::query()->updateOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        $plan = Plan::query()->create([
            'slug' => 'pos_email_' . Str::random(8),
            'name' => 'POS Bulanan',
            'type' => 'subscription',
            'price' => 48000,
            'is_active' => true,
            'billing_cycles' => ['monthly'],
        ]);
        app(PaymentGatewaySettingsService::class)->saveRuntimeConfig(['checkout_mode' => 'gateway_automatic']);
        app(IpaymuSettingsService::class)->saveConfig(['direct_channels' => ['qris']]);
        Http::fake(['*/api/v2/payment/direct*' => Http::response(['Status' => 200, 'Data' => [
            'TransactionId' => 'trx-email-test',
            'QrString' => 'test-qr-payload',
            'QrImage' => 'https://payments.example.test/qr.png',
        ]])]);

        $response = $this->withToken($seller['token'])->postJson('/api/v1/hellom/billing/checkout-start', [
            'app_slug' => 'pos',
            'plan_slug' => $plan->slug,
            'payment_flow' => 'direct',
            'gateway_channel' => 'qris',
        ])->assertOk();

        $buyerMail = collect($mail->queued)->first(fn (array $entry) =>
            in_array($seller['user']->email, (array) $entry['to'], true)
            && $entry['mailable'] instanceof HellomCheckoutStatusMail
            && ($entry['mailable']->payload['headline'] ?? '') === 'Checkout aplikasi Anda sudah dibuat'
        );
        $this->assertNotNull($buyerMail);
        $payload = $buyerMail['mailable']->payload;
        $this->assertSame('https://payments.example.test/qr.png', data_get($payload, 'gateway_instructions.qr_image_url'));
        $this->assertSame('test-qr-payload', data_get($payload, 'gateway_instructions.qr_string'));
        $this->assertSame('Cek status pembayaran', $payload['status_label']);
        $this->assertStringEndsWith('/dashboard/payments?checkout=pos', $payload['status_url']);
        $this->assertArrayHasKey('Batas pembayaran', $payload['details']);
        $rendered = $buyerMail['mailable']->render();
        $this->assertStringContainsString('https://payments.example.test/qr.png', $rendered);
        $this->assertStringContainsString($payload['status_url'], $rendered);

        $intent = CheckoutIntent::query()
            ->where('intent_token', $response->json('data.checkout_intent.intent_token'))
            ->firstOrFail();
        app(\App\Services\Billing\CheckoutNotifier::class)->sendCheckoutStartedNotifications(
            $intent,
            null,
            'https://payments.example.test/continue'
        );
        $hostedPaymentMail = collect($mail->queued)->first(fn (array $entry) =>
            in_array($seller['user']->email, (array) $entry['to'], true)
            && $entry['mailable'] instanceof HellomCheckoutStatusMail
            && ($entry['mailable']->payload['cta_url'] ?? '') === 'https://payments.example.test/continue'
        );
        $this->assertNotNull($hostedPaymentMail);
        $this->assertSame('Bayar sekarang', $hostedPaymentMail['mailable']->payload['cta_label']);
        $this->assertSame($payload['status_url'], $hostedPaymentMail['mailable']->payload['status_url']);
    }

    public function test_direct_pos_checkout_expires_after_one_hour_and_can_be_cancelled(): void
    {
        $seller = $this->seller();
        AppCatalog::query()->updateOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        $plan = Plan::query()->create([
            'slug' => 'pos_cancel_' . Str::random(8),
            'name' => 'POS Bulanan',
            'type' => 'subscription',
            'price' => 48000,
            'is_active' => true,
            'billing_cycles' => ['monthly'],
        ]);
        app(PaymentGatewaySettingsService::class)->saveRuntimeConfig(['checkout_mode' => 'gateway_automatic']);
        app(IpaymuSettingsService::class)->saveConfig(['direct_channels' => ['qris']]);
        Http::fake(['*/api/v2/payment/direct*' => Http::response(['Status' => 200, 'Data' => [
            'TransactionId' => 'trx-cancel-test',
            'PaymentNo' => '880812345678',
            'QrString' => 'test-qr',
        ]])]);

        $response = $this->withToken($seller['token'])->postJson('/api/v1/hellom/billing/checkout-start', [
            'app_slug' => 'pos',
            'plan_slug' => $plan->slug,
            'payment_flow' => 'direct',
            'gateway_channel' => 'qris',
        ])->assertOk();

        $intentToken = (string) $response->json('data.checkout_intent.intent_token');
        $intent = CheckoutIntent::query()->where('intent_token', $intentToken)->firstOrFail();
        $this->assertNotNull($intent->expires_at);
        $this->assertTrue($intent->expires_at->equalTo($intent->created_at->copy()->addHour()));
        $response->assertJsonPath('data.payment.instructions.expires_at', $intent->expires_at->toIso8601String());

        $this->withToken($seller['token'])->postJson('/api/v1/hellom/billing/checkout-cancel', [
            'intent_token' => $intentToken,
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', $intent->fresh()->status);
        $this->assertSame('cancelled', $intent->subscription->fresh()->status);
        $this->assertDatabaseHas('invoices', [
            'subscription_id' => $intent->subscription_id,
            'status' => 'cancelled',
        ]);
    }

    public function test_expired_checkout_cannot_be_cancelled_or_activated(): void
    {
        $seller = $this->seller();
        AppCatalog::query()->updateOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        $plan = Plan::query()->create([
            'slug' => 'pos_expire_' . Str::random(8),
            'name' => 'POS Bulanan',
            'type' => 'subscription',
            'price' => 48000,
            'is_active' => true,
            'billing_cycles' => ['monthly'],
        ]);
        app(PaymentGatewaySettingsService::class)->saveRuntimeConfig(['checkout_mode' => 'gateway_automatic']);
        app(IpaymuSettingsService::class)->saveConfig(['direct_channels' => ['qris']]);
        Http::fake(['*/api/v2/payment/direct*' => Http::response(['Status' => 200, 'Data' => [
            'TransactionId' => 'trx-expire-test',
            'PaymentNo' => '880812345678',
            'QrString' => 'test-qr',
        ]])]);

        $response = $this->withToken($seller['token'])->postJson('/api/v1/hellom/billing/checkout-start', [
            'app_slug' => 'pos',
            'plan_slug' => $plan->slug,
            'payment_flow' => 'direct',
            'gateway_channel' => 'qris',
        ])->assertOk();
        $intent = CheckoutIntent::query()
            ->where('intent_token', $response->json('data.checkout_intent.intent_token'))
            ->firstOrFail();
        $intent->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->withToken($seller['token'])->postJson('/api/v1/hellom/billing/checkout-cancel', [
            'intent_token' => $intent->intent_token,
        ])->assertOk()->assertJsonPath('data.status', 'expired');
        $this->assertSame('cancelled', $intent->subscription->fresh()->status);

        $activation = app(\App\Services\Hellom\SubscriptionCheckoutActivationService::class);
        $this->assertFalse($activation->confirmGatewayCheckout($intent->fresh(), ['transaction_id' => 'trx-late'], 'iPaymu'));
        $this->assertSame('expired', $intent->fresh()->status);
        $this->assertSame('cancelled', $intent->subscription->fresh()->status);
    }

    private function checkout(bool $direct, string $channel = 'bni'): void
    {
        $seller = $this->seller();
        AppCatalog::query()->updateOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        $plan = Plan::query()->create(['slug' => 'pos_test_' . Str::random(8), 'name' => 'POS Tahunan', 'type' => 'subscription', 'price' => 48000, 'is_active' => true, 'billing_cycles' => ['yearly']]);
        app(PaymentGatewaySettingsService::class)->saveRuntimeConfig(['checkout_mode' => 'gateway_automatic']);
        app(IpaymuSettingsService::class)->saveConfig(['direct_channels' => ['bni', 'qris']]);
        Http::fake(['*/api/v2/payment*' => Http::response(['Status' => 200, 'Data' => [
            'Url' => 'https://sandbox.ipaymu.com/payment/test', 'SessionID' => 'session-test', 'TransactionId' => 'trx-test', 'PaymentNo' => '880812345678',
        ]])]);

        $this->withToken($seller['token'])->postJson('/api/v1/hellom/billing/checkout-start', [
            'app_slug' => 'pos', 'plan_slug' => $plan->slug, 'payment_flow' => 'direct', 'billing_cycle' => 'yearly', 'expected_amount' => 47000,
        ])->assertStatus(422)->assertJsonPath('error.code', 'PRICE_CHANGED');
        Http::assertNothingSent();

        $response = $this->withToken($seller['token'])->postJson('/api/v1/hellom/billing/checkout-start', [
            'marketing' => ['consent' => 'granted', 'visitor_id' => 'visitor-price-test', 'session_id' => 'session-price-test', 'source' => 'instagram', 'campaign' => 'pos-launch', 'path' => '/aplikasi/pos'],
            'app_slug' => 'pos', 'plan_slug' => $plan->slug, 'payment_flow' => 'direct', 'billing_cycle' => 'yearly',
            ...($direct ? ['gateway_channel' => $channel] : []),
        ])->assertOk()->assertJsonPath('data.checkout_intent.amount', 48000)->assertJsonPath('data.subscription_draft.amount', 48000);

        $intent = CheckoutIntent::query()->where('intent_token', $response->json('data.checkout_intent.intent_token'))->firstOrFail();
        $this->assertDatabaseHas('platform_marketing_events', ['event_id' => 'initiatecheckout_' . $intent->intent_token, 'amount' => 48000]);
        $this->assertDatabaseMissing('platform_marketing_events', ['event_id' => 'purchase_' . $intent->intent_token]);
        // Only a server-confirmed transition can create a Purchase. Repeated saves remain one event.
        $originalStatus = $intent->status;
        $intent->update(['status' => 'confirmed']);
        $intent->save();
        $this->assertSame(1, \App\Models\PlatformMarketingEvent::where('event_id', 'purchase_' . $intent->intent_token)->count());
        $intent->update(['status' => $originalStatus]);
        $this->assertSame(48000, (int) Invoice::query()->where('subscription_id', $intent->subscription_id)->value('total'));
        Http::assertSent(function ($request) use ($direct, $intent) {
            parse_str(parse_url($request['notifyUrl'], PHP_URL_QUERY), $query);
            $this->assertTrue(IpaymuPaymentVerifier::signatureValid($query));
            return ($direct ? $request['amount'] : $request['price'][0]) === 48000
                && $request['referenceId'] === $intent->intent_token;
        });
        if ($direct) {
            $response->assertJsonPath('data.payment.payment_url', null)
                ->assertJsonPath('data.payment.instructions.' . ($channel === 'qris' ? 'qr_string' : 'va_number'), '880812345678')
                ->assertJsonPath('data.payment.instructions.amount', 48000);
            $this->assertSame('trx-test', $intent->metadata['ipaymu']['transaction_id']);
            $this->getJson('/api/v1/hellom/billing/history')->assertOk()
                ->assertJsonPath('data.checkout_intents.0.payment_instructions.amount', 48000);
        }
    }
}
