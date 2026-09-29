<?php

namespace Tests\Landing;

use App\Http\Middleware\Api\EnsureAppEntitlement;
use App\Jobs\SendLandingSaleEmails;
use App\Models\ApiToken;
use App\Models\LandingBlock;
use App\Models\LandingCoupon;
use App\Models\LandingPageOrder;
use App\Models\LandingProduct;
use App\Models\LandingRefund;
use App\Models\LandingReport;
use App\Models\SellerBalance;
use App\Models\User;
use App\Services\Landing\ProductService;
use App\Services\SellerFinance\LandingPaymentService;
use App\Services\SellerFinance\SellerLedger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/** Fase 3: products, checkout, access page, refunds, trust & moderation. */
class LandingStoreTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    private const DRIVE = 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUv/view?usp=sharing';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAppEntitlement::class); // subscription gate is covered elsewhere
        Storage::fake('local');
        Storage::fake('public');
    }

    private function product(array $seller, array $overrides = []): LandingProduct
    {
        return app(ProductService::class)->save($seller['org'], $overrides + [
            'type' => 'drive', 'name' => 'E-book Jualan Online', 'price' => 100000, 'delivery_url' => self::DRIVE,
        ]);
    }

    private function fakeGateway(): void
    {
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake([
            '*/api/v2/payment' => Http::response(['Status' => 200, 'Data' => ['SessionID' => 'sess-1', 'Url' => 'https://sandbox.ipaymu.com/pay/sess-1']]),
            '*/api/v2/payment/direct' => Http::response(['Status' => 200, 'Data' => ['SessionId' => 'sess-q', 'TransactionId' => 'trx-q', 'QrString' => '000201', 'QrImage' => 'https://sandbox.ipaymu.com/qr.png']]),
        ]);
    }

    private function checkout(LandingProduct $product, array $input = []): \Illuminate\Testing\TestResponse
    {
        $this->fakeGateway();

        return $this->postJson("/api/v1/hellom/public/landing-products/{$product->public_id}/checkout", $input + [
            'buyer_name' => 'Sari Pembeli', 'buyer_email' => 'sari@example.test',
        ]);
    }

    /** Pay an order through the real webhook path (gateway API faked). */
    private function pay(LandingPageOrder $order): LandingPageOrder
    {
        $trx = 'trx-' . $order->id;
        $this->fakeIpaymuTransaction($trx, (string) $order->reference_id, (int) $order->amount);
        $this->ipaymuWebhook($order, $trx)->assertOk();

        return $order->fresh();
    }

    private function adminToken(): string
    {
        $admin = User::query()->create(['name' => 'SA', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'super_admin']);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $admin->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);

        return $plain;
    }

    public function test_drive_link_is_encrypted_validated_and_never_public(): void
    {
        $seller = $this->seller();
        $this->withHeader('Authorization', "Bearer {$seller['token']}")
            ->postJson('/api/v1/hellom/apps/landing-builder/products', ['type' => 'drive', 'name' => 'E-book', 'price' => 50000, 'delivery_url' => 'https://example.com/file.pdf'])
            ->assertStatus(422)->assertJsonValidationErrors('delivery_url');

        $created = $this->withHeader('Authorization', "Bearer {$seller['token']}")
            ->postJson('/api/v1/hellom/apps/landing-builder/products', ['type' => 'drive', 'name' => 'E-book', 'price' => 50000, 'delivery_url' => self::DRIVE, 'description' => '<p>Isi <script>alert(1)</script></p>'])
            ->assertCreated()->assertJsonPath('data.delivery_url', self::DRIVE)->json('data');
        $product = LandingProduct::query()->findOrFail($created['db_id']);

        // Stored encrypted; description sanitised.
        $this->assertStringNotContainsString('drive.google.com', (string) DB::table('landing_products')->where('id', $product->id)->value('delivery_url'));
        $this->assertStringNotContainsString('<script', (string) $product->description);

        // Public product and landing page JSON never carry the link.
        $publicProduct = $this->getJson("/api/v1/hellom/public/landing-products/{$product->public_id}")->assertOk()->getContent();
        LandingBlock::query()->create(['organization_id' => $seller['org']->id, 'landing_page_id' => $seller['page']->id, 'block_key' => 'p2', 'block_type' => 'product',
            'sort_order' => 2, 'is_visible' => true, 'content' => ['productId' => $product->public_id, 'buttonText' => 'Beli']]);
        $page = $this->getJson("/api/v1/hellom/public/landingpage/{$seller['org']->slug}")->assertOk();
        $this->assertSame($product->public_id, collect($page->json('data.blocks'))->firstWhere('block_key', 'p2')['product']['id']);
        foreach ([$publicProduct, $page->getContent()] as $json) {
            $this->assertStringNotContainsString('1AbCdEfGhIjKlMnOpQrStUv', $json);
            $this->assertStringNotContainsString('rahasia', $json);
        }
    }

    public function test_file_upload_is_private_limited_to_10mb_and_known_types(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, ['type' => 'file', 'name' => 'Template', 'price' => 30000]);
        $auth = ['Authorization' => "Bearer {$seller['token']}", 'Accept' => 'application/json'];

        $this->post("/api/v1/hellom/apps/landing-builder/products/{$product->id}/file", ['file' => UploadedFile::fake()->create('big.pdf', 10241)], $auth)
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post("/api/v1/hellom/apps/landing-builder/products/{$product->id}/file", ['file' => UploadedFile::fake()->create('shell.php', 10)], $auth)
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post("/api/v1/hellom/apps/landing-builder/products/{$product->id}/file", ['file' => UploadedFile::fake()->create('panduan.pdf', 500)], $auth)
            ->assertOk()->assertJsonPath('data.file_name', 'panduan.pdf')->assertJsonPath('data.deliverable', true);

        $path = (string) $product->fresh()->file_path;
        Storage::disk('local')->assertExists($path);
        $this->assertStringNotContainsString('panduan', $path); // random name
        Storage::disk('public')->assertMissing($path);
    }

    public function test_checkout_uses_database_price_coupon_and_reserves_stock(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, ['type' => 'physical', 'name' => 'Kaos', 'price' => 80000, 'stock' => 2, 'shipping_mode' => 'flat', 'shipping_fee' => 15000]);
        $product->forceFill(['delivery_url' => null])->save();
        LandingCoupon::query()->create(['organization_id' => $seller['org']->id, 'code' => 'HEMAT10', 'type' => 'percent', 'value' => 10, 'max_uses' => 1]);
        $address = ['recipient_name' => 'Sari', 'phone' => '081234567890', 'address' => 'Jl. Merdeka No. 10 RT 1', 'city' => 'Bandung', 'postal_code' => '40111'];

        // Physical needs address + phone.
        $this->checkout($product, ['quantity' => 2])->assertStatus(422)->assertJsonValidationErrors(['buyer_phone', 'shipping.address']);

        $this->checkout($product, ['quantity' => 2, 'coupon_code' => 'hemat10', 'buyer_phone' => '081234567890', 'shipping' => $address, 'price' => 1, 'amount' => 1])
            ->assertCreated()->assertJsonPath('data.mode', 'redirect');
        $order = LandingPageOrder::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
        // 2 × 80.000 = 160.000 − 10% (16.000) + 15.000 ongkir; price fields from the client ignored.
        $this->assertSame(160000, (int) $order->subtotal_amount);
        $this->assertSame(16000, (int) $order->discount_amount);
        $this->assertSame(159000, (int) $order->amount);
        // Physical products hold stock, so they expire sooner (default 6 hours instead of 24).
        $this->assertEqualsWithDelta(now()->addHours(6)->timestamp, $order->expires_at->timestamp, 60);
        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertSame(1, (int) LandingCoupon::query()->where('code', 'HEMAT10')->value('used_count'));

        // Sold out + coupon used up.
        $this->checkout($product, ['buyer_phone' => '081234567890', 'shipping' => $address])->assertStatus(422)->assertJsonValidationErrors('product');

        // Expiry gives stock and coupon back.
        $order->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->assertTrue(app(LandingPaymentService::class)->markExpired($order));
        $this->assertSame(2, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) LandingCoupon::query()->where('code', 'HEMAT10')->value('used_count'));
    }

    public function test_access_page_limits_opens_and_follows_the_latest_link(): void
    {
        Queue::fake();
        $seller = $this->seller();
        $product = $this->product($seller, ['access_max_opens' => 2]);
        $this->checkout($product)->assertCreated();
        $order = LandingPageOrder::query()->where('product_id', $product->id)->firstOrFail();

        // No token before payment.
        $this->assertNull($order->download_token);
        $order = $this->pay($order);
        $token = (string) $order->download_token;
        $this->assertSame(1, (int) $product->fresh()->sold_count);

        $this->getJson("/api/v1/hellom/public/landing-access/{$token}")->assertOk()
            ->assertJsonPath('data.access.opens_max', 2)->assertJsonMissingPath('data.url');
        $this->postJson("/api/v1/hellom/public/landing-access/{$token}/open")->assertOk()->assertJsonPath('data.url', self::DRIVE);

        // Seller replaces the link: the same access page now opens the new one.
        $new = 'https://drive.google.com/drive/folders/1ZyXwVuTsRqPoNmLkJiHg';
        app(ProductService::class)->save($seller['org'], ['type' => 'drive', 'name' => 'E-book Jualan Online', 'price' => 100000, 'delivery_url' => $new, 'access_max_opens' => 2], $product);
        $this->postJson("/api/v1/hellom/public/landing-access/{$token}/open")->assertOk()->assertJsonPath('data.url', $new);
        $this->postJson("/api/v1/hellom/public/landing-access/{$token}/open")->assertStatus(422);

        $this->getJson('/api/v1/hellom/public/landing-access/' . str_repeat('x', 48))->assertNotFound();
    }

    public function test_file_download_needs_a_fresh_signed_link_and_respects_the_limit(): void
    {
        Queue::fake();
        $seller = $this->seller();
        $product = $this->product($seller, ['type' => 'file', 'name' => 'Preset', 'price' => 25000, 'download_limit' => 1]);
        app(ProductService::class)->storeFile($product, UploadedFile::fake()->createWithContent('preset.zip', 'ZIPDATA'));
        $this->checkout($product->fresh())->assertCreated();
        $order = $this->pay(LandingPageOrder::query()->where('product_id', $product->id)->firstOrFail());
        $token = (string) $order->download_token;

        $this->get("/api/v1/hellom/public/landing-access/{$token}/download")->assertForbidden(); // unsigned
        $url = $this->postJson("/api/v1/hellom/public/landing-access/{$token}/open")->assertOk()->json('data.url');
        $this->get($url)->assertOk()->assertDownload('preset.zip');
        $this->get($url)->assertStatus(422); // limit 1
        $this->assertSame(1, (int) $order->fresh()->download_count);
    }

    public function test_order_lookup_only_emails_the_buyer_and_reveals_nothing(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);
        $this->checkout($product)->assertCreated();
        $order = LandingPageOrder::query()->where('product_id', $product->id)->firstOrFail();
        $order = $this->pay($order);
        Queue::fake();

        $wrong = $this->postJson('/api/v1/hellom/public/landing-orders/lookup', ['email' => 'orang-lain@example.test', 'reference' => $order->reference_id])->assertOk();
        Queue::assertNothingPushed();
        $right = $this->postJson('/api/v1/hellom/public/landing-orders/lookup', ['email' => 'SARI@example.test', 'reference' => $order->reference_id])->assertOk();
        Queue::assertPushed(SendLandingSaleEmails::class, fn ($job) => $job->orderId === $order->id && $job->buyerOnly);
        $this->assertSame($wrong->json('message'), $right->json('message'));
        $this->assertStringNotContainsString((string) $order->download_token, $right->getContent());
    }

    public function test_sellers_cannot_touch_each_others_products_orders_or_coupons(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        $productB = $this->product($b);
        $this->checkout($productB)->assertCreated();
        $orderB = LandingPageOrder::query()->where('product_id', $productB->id)->firstOrFail();
        $couponB = LandingCoupon::query()->create(['organization_id' => $b['org']->id, 'code' => 'B10', 'type' => 'fixed', 'value' => 10000]);
        $auth = ['Authorization' => "Bearer {$a['token']}"];

        $this->getJson("/api/v1/hellom/apps/landing-builder/products/{$productB->id}", $auth)->assertNotFound();
        $this->putJson("/api/v1/hellom/apps/landing-builder/products/{$productB->id}", ['type' => 'drive', 'name' => 'Hack', 'price' => 10000, 'delivery_url' => self::DRIVE], $auth)->assertNotFound();
        $this->deleteJson("/api/v1/hellom/apps/landing-builder/coupons/{$couponB->id}", [], $auth)->assertNotFound();
        $this->getJson("/api/v1/hellom/seller/orders/{$orderB->id}", $auth)->assertNotFound();
        $this->postJson("/api/v1/hellom/seller/orders/{$orderB->id}/refund", [], $auth)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/hellom/apps/landing-builder/products', $auth)->assertOk()->json('data.items'));
        $this->assertSame(0, $this->getJson('/api/v1/hellom/seller/orders', $auth)->assertOk()->json('data.total'));

        // A coupon of shop B does not work in shop A.
        $productA = $this->product($a);
        $this->checkout($productA, ['coupon_code' => 'B10'])->assertStatus(422)->assertJsonValidationErrors('coupon_code');
    }

    public function test_refund_takes_money_from_the_seller_and_failure_returns_it(): void
    {
        Queue::fake();
        $seller = $this->seller();
        $product = $this->product($seller);
        $this->checkout($product)->assertCreated();
        $order = $this->pay(LandingPageOrder::query()->where('product_id', $product->id)->firstOrFail());
        $auth = ['Authorization' => "Bearer {$seller['token']}"];
        $dest = ['reason' => 'Pembeli salah beli produk', 'destination_type' => 'bank', 'bank_code' => 'BRI', 'account_number' => '0012345678', 'account_name' => 'Sari'];

        // Net 95.000 available; a full 100.000 refund is more than the balance.
        $this->postJson("/api/v1/hellom/seller/orders/{$order->id}/refund", $dest + ['amount' => 100000], $auth)->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');
        $this->postJson("/api/v1/hellom/seller/orders/{$order->id}/refund", $dest + ['amount' => 90000], $auth)->assertCreated()->assertJsonPath('data.can_refund', false);
        $this->assertSame(5000, (int) SellerBalance::query()->find($seller['org']->id)->available);

        $refund = LandingRefund::query()->where('order_id', $order->id)->firstOrFail();
        $admin = $this->adminToken();
        $this->postJson("/api/v1/hellom/admin/seller-finance/refunds/{$refund->id}/mark-failed", ['reason' => 'Rekening pembeli salah'], ['Authorization' => "Bearer {$admin}"])->assertOk();
        $this->assertSame(95000, (int) SellerBalance::query()->find($seller['org']->id)->available);
        $this->assertTrue($order->fresh()->isPaid());

        // Second request, paid by Hellom → order refunded.
        $this->postJson("/api/v1/hellom/seller/orders/{$order->id}/refund", $dest + ['amount' => 50000], $auth)->assertCreated();
        $refund2 = LandingRefund::query()->where('order_id', $order->id)->where('status', 'requested')->firstOrFail();
        $this->postJson("/api/v1/hellom/admin/seller-finance/refunds/{$refund2->id}/mark-paid", [], ['Authorization' => "Bearer {$admin}"])->assertOk();
        $this->assertSame(LandingPageOrder::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertSame(45000, (int) SellerBalance::query()->find($seller['org']->id)->available);
        $this->assertTrue(app(SellerLedger::class)->reconcile($seller['org']->id)['ok']);
        // Refunded orders lose access.
        $this->getJson("/api/v1/hellom/public/landing-access/{$order->download_token}")->assertNotFound();
    }

    public function test_suspended_seller_and_disabled_product_cannot_sell(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);
        $admin = ['Authorization' => "Bearer {$this->adminToken()}"];

        $this->postJson("/api/v1/hellom/admin/landing-moderation/products/{$product->id}/disable", ['disabled' => true, 'reason' => 'Produk bajakan'], $admin)->assertOk();
        $this->getJson("/api/v1/hellom/public/landing-products/{$product->public_id}")->assertOk()->assertJsonPath('data.product.available', false);
        $this->checkout($product)->assertStatus(422);

        $this->postJson("/api/v1/hellom/admin/landing-moderation/sellers/{$seller['org']->id}/suspend", ['suspended' => true, 'reason' => 'Laporan penipuan'], $admin)->assertOk();
        $this->getJson("/api/v1/hellom/public/landing-products/{$product->public_id}")->assertStatus(410);
        $this->getJson("/api/v1/hellom/public/landingpage/{$seller['org']->slug}")->assertStatus(410)->assertJsonPath('error.code', 'SELLER_SUSPENDED');
        $this->postJson("/api/v1/hellom/public/landingpage/{$seller['org']->slug}/orders", ['block_id' => $seller['block']->id, 'buyer_name' => 'X', 'buyer_email' => 'x@example.test'])->assertNotFound();

        // A seller cannot use moderation endpoints.
        $this->postJson("/api/v1/hellom/admin/landing-moderation/sellers/{$seller['org']->id}/suspend", ['suspended' => false], ['Authorization' => "Bearer {$seller['token']}"])->assertForbidden();
    }

    public function test_report_and_verified_badge_and_email_verification(): void
    {
        $seller = $this->seller();
        $this->postJson('/api/v1/hellom/public/landing-reports', ['organization_slug' => $seller['org']->slug, 'reason' => 'scam', 'description' => 'Barang tidak dikirim'])->assertCreated();
        $this->assertSame(1, LandingReport::query()->where('organization_id', $seller['org']->id)->count());

        // Verified payout profile + owner email verified → badge.
        $this->getJson("/api/v1/hellom/public/landingpage/{$seller['org']->slug}")->assertOk()->assertJsonPath('data.seller.verified', true);
        $seller['user']->forceFill(['email_verified_at' => null])->save();
        $this->getJson("/api/v1/hellom/public/landingpage/{$seller['org']->slug}")->assertOk()->assertJsonPath('data.seller.verified', false);

        // Signed link verifies; a tampered one does not.
        $link = URL::temporarySignedRoute('api.v1.hellom.public.email.verify', now()->addHour(), ['id' => $seller['user']->id, 'hash' => sha1((string) $seller['user']->email)]);
        $this->get($link . 'x')->assertRedirect();
        $this->assertNull($seller['user']->fresh()->email_verified_at);
        $this->get($link)->assertRedirect();
        $this->assertNotNull($seller['user']->fresh()->email_verified_at);
    }

    public function test_service_product_asks_what_the_buyer_needs(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, ['type' => 'service', 'name' => 'Konsultasi', 'price' => 150000]);

        $this->checkout($product)->assertStatus(422)->assertJsonValidationErrors('fields.kebutuhan');
        $this->checkout($product, ['fields' => ['kebutuhan' => 'Bikin logo toko kue']])->assertCreated();
        $order = LandingPageOrder::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame([['label' => 'Ceritakan kebutuhan kamu', 'value' => 'Bikin logo toko kue']], $order->custom_fields);
    }
}
