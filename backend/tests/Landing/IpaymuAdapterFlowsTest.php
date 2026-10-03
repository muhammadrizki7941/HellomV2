<?php

namespace Tests\Landing;

use App\Models\DigitalProduct;
use App\Models\User;
use App\Services\DigitalProducts\ProductCheckoutService;
use App\Services\Hellom\PaymentGatewaySettingsService;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\IpaymuPaymentVerifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fase 2 (gateway agnostik): Hellom's own digital products and the iPaymu balance go
 * through IpaymuGateway — one request builder, one signed notify URL, one parser.
 */
class IpaymuAdapterFlowsTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PaymentGatewaySettingsService::class)->saveRuntimeConfig(['active_provider' => 'ipaymu', 'checkout_mode' => 'gateway_automatic']);
    }

    private function buyerAndProduct(): array
    {
        $user = User::query()->create(['name' => 'Dina', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('x'), 'role' => 'member']);
        $product = DigitalProduct::query()->create(['slug' => 'kelas-' . Str::lower(Str::random(6)), 'name' => 'Kelas Jualan', 'category' => 'course', 'type' => 'paid',
            'price' => 120000, 'currency' => 'IDR', 'is_published' => true]);

        return [$user, $product];
    }

    public function test_own_product_qris_uses_the_adapter_and_reads_the_code_from_payment_no(): void
    {
        [$user, $product] = $this->buyerAndProduct();
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake(['*/api/v2/payment/direct' => Http::response(['Status' => 200, 'Data' => [
            'SessionId' => 's-1', 'TransactionId' => 't-1', 'PaymentNo' => '000201010212QRISCODE', 'Total' => 120000, 'Fee' => 840, 'Expired' => '2026-10-04 10:00:00',
        ]])]);

        $result = app(ProductCheckoutService::class)->start($user, $product, ['payment_flow' => 'gateway', 'gateway_channel' => 'qris']);

        $this->assertTrue($result['ok'], json_encode($result));
        $instructions = $result['data']['payment_instructions'];
        $this->assertSame('000201010212QRISCODE', $instructions['qr_string']);
        $this->assertArrayNotHasKey('va_number', $instructions);
        $this->assertSame(840, $instructions['fee']);
        $this->assertSame('t-1', $instructions['transaction_id']);

        // The notify URL is signed and binds the purchase to it.
        Http::assertSent(function ($request) {
            parse_str((string) parse_url((string) ($request->data()['notifyUrl'] ?? ''), PHP_URL_QUERY), $query);

            return ($query['purpose'] ?? '') === 'product_purchase' && IpaymuPaymentVerifier::signatureValid($query) === true
                && $request->data()['paymentChannel'] === 'qris';
        });
    }

    public function test_own_product_hosted_page_and_va(): void
    {
        [$user, $product] = $this->buyerAndProduct();
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake([
            '*/api/v2/payment/direct' => Http::response(['Status' => 200, 'Data' => ['SessionId' => 's-2', 'TransactionId' => 't-2', 'PaymentNo' => '8808123412341234']]),
            '*/api/v2/payment' => Http::response(['Status' => 200, 'Data' => ['SessionID' => 'sess-h', 'Url' => 'https://my.ipaymu.com/payment/sess-h']]),
        ]);

        $va = app(ProductCheckoutService::class)->start($user, $product, ['payment_flow' => 'gateway', 'gateway_channel' => 'bca']);
        $this->assertSame('8808123412341234', $va['data']['payment_instructions']['va_number']);
        $this->assertSame('BCA Virtual Account', $va['data']['payment_instructions']['channel_label']);

        [$other] = $this->buyerAndProduct();
        $hosted = app(ProductCheckoutService::class)->start($other, $product, ['payment_flow' => 'gateway']);
        $this->assertSame('https://my.ipaymu.com/payment/sess-h', $hosted['data']['checkout_url']);
    }

    public function test_yearly_subscription_checkout_charges_the_intent_amount_through_the_adapter(): void
    {
        $org = \App\Models\Organization::query()->create(['name' => 'Org ' . Str::random(4), 'slug' => 'org-' . Str::lower(Str::random(8)), 'status' => 'active']);
        $owner = User::query()->create(['name' => 'Owner', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('x'), 'role' => 'admin', 'current_organization_id' => $org->id]);
        $org->users()->attach($owner->id, ['role' => 'owner']);
        \App\Models\AppCatalog::query()->firstOrCreate(['slug' => 'landing_builder'], ['name' => 'Hellom Page', 'is_active' => true]);
        $plan = \App\Models\Plan::query()->create(['slug' => 'landing_pro_' . Str::lower(Str::random(5)), 'name' => 'Pro', 'type' => 'subscription', 'price' => 49000,
            'billing_cycles' => ['monthly', 'yearly'], 'is_active' => true, 'is_visible' => true]);
        $token = Str::random(40);
        \App\Models\ApiToken::query()->create(['user_id' => $owner->id, 'name' => 't', 'token_hash' => hash('sha256', $token)]);
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake(['*/api/v2/payment' => Http::response(['Status' => 200, 'Data' => ['SessionID' => 'sess-sub', 'Url' => 'https://my.ipaymu.com/payment/sess-sub']])]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])->postJson('/api/v1/hellom/billing/checkout-start', [
            'app_slug' => 'landing_builder', 'plan_slug' => $plan->slug, 'payment_flow' => 'direct', 'billing_cycle' => 'yearly',
        ])->assertSuccessful();

        Http::assertSent(function ($request) {
            parse_str((string) parse_url((string) $request->data()['notifyUrl'], PHP_URL_QUERY), $query);

            return str_ends_with($request->url(), '/api/v2/payment') && $request->data()['price'] === [490000]
                && ($query['purpose'] ?? '') === 'subscription_checkout' && IpaymuPaymentVerifier::signatureValid($query) === true;
        });
    }

    public function test_ipaymu_balance_from_the_official_endpoint(): void
    {
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake(['*/api/v2/balance' => Http::response(['Status' => 200, 'Data' => ['Va' => '0000001234567890', 'MerchantBalance' => 1534000.5, 'MemberBalance' => 0], 'Message' => 'success'])]);

        $balance = app(GatewayRegistry::class)->get('ipaymu')->getBalance();

        $this->assertNotNull($balance);
        $this->assertSame(1534001, $balance->available);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v2/balance') && $request->data()['account'] === '0000001234567890');
    }
}
