<?php

namespace Tests\Landing;

use App\Models\AppCatalog;
use App\Models\CheckoutIntent;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Hellom\PaymentGatewaySettingsService;
use App\Services\Payments\IpaymuPaymentVerifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
