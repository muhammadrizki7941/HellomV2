<?php

namespace Tests\Landing;

use App\Http\Middleware\Api\EnsureAppEntitlement;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\LandingPageOrder;
use App\Models\LandingProduct;
use App\Models\User;
use App\Services\Landing\ProductService;
use App\Services\Shipping\ShippingSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fase 3: physical products with real courier rates (RajaOngkir by Komerce, faked here).
 * The server always prices the courier again; the service fee is a share of the product only.
 */
class ShippingTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    private const RATES = [
        ['name' => 'Jalur Nugraha Ekakurir (JNE)', 'code' => 'jne', 'service' => 'REG', 'description' => 'Layanan Reguler', 'cost' => 18000, 'etd' => '2-3 day'],
        ['name' => 'SiCepat Express', 'code' => 'sicepat', 'service' => 'BEST', 'description' => 'Besok Sampai Tujuan', 'cost' => 27000, 'etd' => '1 day'],
        ['name' => 'J&T Express', 'code' => 'jnt', 'service' => 'EZ', 'description' => 'Reguler', 'cost' => 16000, 'etd' => '2-4 day'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAppEntitlement::class);
        app(ShippingSettings::class)->update(['provider' => 'rajaongkir', 'api_key' => 'test-rajaongkir-key', 'couriers' => ['jne', 'jnt', 'sicepat']]);
        $this->fakeApis();
    }

    /** RajaOngkir + iPaymu (payment) fakes; nothing else may be called. */
    private function fakeApis(): void
    {
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake([
            'rajaongkir.komerce.id/api/v1/destination/domestic-destination*' => Http::response(['meta' => ['code' => 200, 'status' => 'success', 'message' => 'Success'], 'data' => [
                ['id' => 31555, 'label' => 'SINDUHARJO, NGAGLIK, SLEMAN, DI YOGYAKARTA, 55581', 'subdistrict_name' => 'SINDUHARJO', 'district_name' => 'NGAGLIK', 'city_name' => 'SLEMAN', 'province_name' => 'DI YOGYAKARTA', 'zip_code' => '55581'],
            ]]),
            'rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response(['meta' => ['code' => 200, 'status' => 'success', 'message' => 'Success'], 'data' => self::RATES]),
            '*/api/v2/payment/direct' => Http::response(['Status' => 200, 'Data' => ['SessionId' => 'sess-q', 'TransactionId' => 'trx-q', 'PaymentNo' => '000201QR', 'Total' => 1, 'Fee' => 0]]),
        ]);
    }

    private function auth(array $seller): array
    {
        return ['Authorization' => 'Bearer ' . $seller['token']];
    }

    /** Seller shipping from Jakarta with a physical product (600 g) using courier rates. */
    private function courierProduct(array $seller, array $overrides = []): LandingProduct
    {
        $this->putJson('/api/v1/hellom/apps/landing-builder/shipping', ['origin_id' => '17473', 'origin_label' => 'GAMBIR, JAKARTA PUSAT, 10110', 'couriers' => ['jne', 'jnt', 'sicepat']], $this->auth($seller))->assertOk();

        return app(ProductService::class)->save($seller['org']->fresh(), $overrides + [
            'type' => 'physical', 'name' => 'Kopi Gayo 250 g', 'price' => 200000, 'stock' => 10, 'shipping_mode' => 'courier', 'weight_grams' => 600,
        ]);
    }

    private function buyerShipping(string $courier = 'jne:REG'): array
    {
        return [
            'recipient_name' => 'Sari', 'phone' => '081234567890', 'address' => 'Jl. Kaliurang Km 9 No. 5',
            'destination_id' => '31555', 'destination_label' => 'SINDUHARJO, NGAGLIK, SLEMAN, DI YOGYAKARTA, 55581', 'courier' => $courier,
        ];
    }

    public function test_super_admin_settings_keep_the_api_key_secret(): void
    {
        $admin = User::query()->create(['name' => 'SA', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('x'), 'role' => 'super_admin']);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $admin->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);
        $headers = ['Authorization' => 'Bearer ' . $plain];

        $response = $this->putJson('/api/v1/hellom/admin/shipping-settings', ['provider' => 'rajaongkir', 'api_key' => 'rahasia-baru-123', 'couriers' => ['jne', 'pos']], $headers)
            ->assertOk()->assertJsonPath('data.api_key_set', true)->assertJsonPath('data.couriers', ['jne', 'pos'])->assertJsonPath('data.ready', true);
        $this->assertStringNotContainsString('rahasia-baru-123', $response->getContent());
        $this->assertSame('rahasia-baru-123', app(ShippingSettings::class)->apiKey());
        $log = AuditLog::query()->where('action', 'shipping.settings_updated')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('rahasia-baru-123', json_encode($log->toArray()));

        // Empty key keeps the saved one; a seller cannot reach these settings.
        $this->putJson('/api/v1/hellom/admin/shipping-settings', ['provider' => 'rajaongkir', 'api_key' => '', 'couriers' => ['jne']], $headers)->assertOk();
        $this->assertSame('rahasia-baru-123', app(ShippingSettings::class)->apiKey());
        $this->getJson('/api/v1/hellom/admin/shipping-settings', $this->auth($this->seller()))->assertForbidden();
        $this->postJson('/api/v1/hellom/admin/shipping-settings/test', [], $headers)->assertOk()->assertJsonPath('data.sample', 'SINDUHARJO, NGAGLIK, SLEMAN, DI YOGYAKARTA, 55581');
    }

    public function test_courier_product_needs_weight_and_a_ship_from_place(): void
    {
        $seller = $this->seller();
        $save = fn (array $data) => app(ProductService::class)->save($seller['org']->fresh(), $data + ['type' => 'physical', 'name' => 'Kaos', 'price' => 90000, 'shipping_mode' => 'courier']);

        try {
            $save(['weight_grams' => 0]);
            $this->fail('weight required');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('weight_grams', $e->errors());
        }
        try {
            $save(['weight_grams' => 300]);
            $this->fail('origin required');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('alamat asal pengiriman', $e->errors()['shipping_mode'][0]);
        }

        $this->getJson('/api/v1/hellom/apps/landing-builder/shipping', $this->auth($seller))->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.origin', null);
        $this->putJson('/api/v1/hellom/apps/landing-builder/shipping', ['origin_id' => '17473', 'origin_label' => 'GAMBIR', 'couriers' => ['pos']], $this->auth($seller))
            ->assertStatus(422)->assertJsonValidationErrors('couriers.0'); // only couriers the platform offers
        $this->putJson('/api/v1/hellom/apps/landing-builder/shipping', ['origin_id' => '17473', 'origin_label' => 'GAMBIR', 'couriers' => ['jne']], $this->auth($seller))->assertOk()
            ->assertJsonPath('data.origin.id', '17473')->assertJsonPath('data.couriers', ['jne']);
        $this->assertSame('courier', $save(['weight_grams' => 300])->shipping_mode);
    }

    public function test_buyer_gets_cached_destinations_and_rates(): void
    {
        $seller = $this->seller();
        $product = $this->courierProduct($seller);

        $this->getJson('/api/v1/hellom/public/shipping/destinations?q=ngaglik')->assertOk()->assertJsonPath('data.items.0.id', '31555')->assertJsonPath('data.items.0.postal_code', '55581');
        $this->getJson('/api/v1/hellom/public/shipping/destinations?q=NGAGLIK')->assertOk(); // same search, from the cache
        $this->getJson('/api/v1/hellom/public/shipping/destinations?q=ab')->assertStatus(422);

        $rates = $this->postJson("/api/v1/hellom/public/landing-products/{$product->public_id}/shipping-rates", ['destination_id' => '31555', 'quantity' => 2])
            ->assertOk()->json('data.items');
        $this->assertSame(['jnt:EZ', 'jne:REG', 'sicepat:BEST'], array_column($rates, 'key')); // cheapest first
        $this->assertSame('JNE REG', $rates[1]['label']);
        $this->postJson("/api/v1/hellom/public/landing-products/{$product->public_id}/shipping-rates", ['destination_id' => '31555', 'quantity' => 2])->assertOk();

        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'domestic-destination')));
        $costCalls = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'domestic-cost'));
        $this->assertCount(1, $costCalls);
        [$request] = $costCalls->first();
        // 2 × 600 g = 1.200 g, from the shop's place, couriers of the shop, the saved key.
        $this->assertSame(['origin' => '17473', 'destination' => '31555', 'weight' => 1200, 'courier' => 'jne:jnt:sicepat', 'price' => 'lowest'], $request->data());
        $this->assertSame('test-rajaongkir-key', $request->header('key')[0]);
    }

    public function test_checkout_prices_the_courier_on_the_server_and_the_fee_skips_shipping(): void
    {
        $seller = $this->seller();
        $product = $this->courierProduct($seller);
        $checkout = fn (array $input) => $this->postJson("/api/v1/hellom/public/landing-products/{$product->public_id}/checkout", $input + [
            'buyer_name' => 'Sari Pembeli', 'buyer_email' => 'sari@example.test', 'buyer_phone' => '081234567890',
        ]);

        // A courier the API does not offer for this route is refused; client prices are ignored.
        $checkout(['shipping' => $this->buyerShipping('pos:KILAT'), 'shipping_amount' => 1])->assertStatus(422)->assertJsonValidationErrors('shipping.courier');
        $checkout(['shipping' => array_diff_key($this->buyerShipping(), ['destination_id' => 1])])->assertStatus(422)->assertJsonValidationErrors('shipping.destination_id');

        $this->postJson("/api/v1/hellom/public/landing-products/{$product->public_id}/quote", ['destination_id' => '31555', 'courier' => 'jne:REG'])->assertOk()
            ->assertJsonPath('data.shipping', 18000)->assertJsonPath('data.total', 218000)->assertJsonPath('data.shipping_rate.label', 'JNE REG');

        $checkout(['shipping' => $this->buyerShipping('jne:REG')])->assertCreated();
        $order = LandingPageOrder::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
        $this->assertSame(18000, (int) $order->shipping_amount);
        $this->assertSame(218000, (int) $order->amount);
        $this->assertSame('JNE REG', $order->shipping_courier);
        $this->assertSame('SINDUHARJO, NGAGLIK, SLEMAN, DI YOGYAKARTA, 55581', $order->shipping_address['destination_label']);
        $this->assertSame('2-3 day', $order->shipping_address['etd']);

        // Paid: the 5% service fee is on the 200.000 product price, not on the 18.000 shipping.
        $order->forceFill(['provider' => 'ipaymu', 'gateway_ref' => 'sess-' . $order->id])->save();
        $this->fakeIpaymuTransaction('trx-ship', (string) $order->reference_id, 218000, 1, 4000);
        $this->ipaymuWebhook($order, 'trx-ship')->assertOk();
        $paid = $order->fresh();
        $this->assertSame(LandingPageOrder::STATUS_PAID, $paid->status);
        $this->assertSame(10000, (int) $paid->commission_amount);
        $this->assertSame(208000, (int) $paid->net_amount); // product − fee + the full shipping for the seller
    }
}
