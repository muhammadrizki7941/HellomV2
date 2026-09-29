<?php

namespace Tests\Landing;

use App\Http\Middleware\Api\EnsureAppEntitlement;
use App\Jobs\SendMetaPurchaseEvent;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\LandingPageOrder;
use App\Models\LandingTrackingSetting;
use App\Models\OrganizationLandingPage;
use App\Models\Plan;
use App\Services\Landing\LandingDocumentService;
use App\Services\Landing\ProductService;
use App\Support\Landing\BlockSchema;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Fase 4: documents (draft/publish), server-rendered pages, username, quota, ads, stats. */
class LandingSiteTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    private const DRIVE = 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUv/view';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAppEntitlement::class);
        Storage::fake('public');
        Storage::fake('local');
    }

    private function auth(array $seller): array
    {
        return ['Authorization' => "Bearer {$seller['token']}"];
    }

    /** Seller with a published home page that features a Drive product. */
    private function shop(): array
    {
        $seller = $this->seller();
        $seller['org']->forceFill(['landing_username' => 'toko' . $seller['org']->id])->save();
        $product = app(ProductService::class)->save($seller['org'], ['type' => 'drive', 'name' => 'E-book Resep', 'price' => 49000, 'delivery_url' => self::DRIVE,
            'description' => '<p>Isi e-book</p>']);
        $documents = app(LandingDocumentService::class);
        $documents->saveDraft($seller['page'], ['theme' => ['preset' => 'ocean'], 'blocks' => [
            ['id' => 'p1', 'type' => 'profile', 'content' => ['name' => 'Toko Resep Bu Sari', 'bio' => 'Resep rumahan']],
            ['id' => 'x1', 'type' => 'product', 'content' => ['productId' => $product->public_id, 'buttonText' => 'Beli E-book']],
        ]], null);
        $documents->publish($seller['page']->fresh(), $seller['user']);

        return $seller + ['product' => $product, 'username' => 'toko' . $seller['org']->id];
    }

    public function test_block_schema_keeps_structured_data_only(): void
    {
        $doc = BlockSchema::normalize(['theme' => ['primary' => 'red;}body{display:none', 'font' => 'comic'], 'blocks' => [
            ['id' => 'a', 'type' => 'button', 'content' => ['text' => 'Klik', 'linkUrl' => 'javascript:alert(1)', 'onclick' => 'x()']],
            ['id' => 'b', 'type' => 'image', 'content' => ['imageUrl' => 'data:image/png;base64,AAAA']],
            ['id' => 'c', 'type' => 'html', 'content' => ['html' => '<p>Hai</p><script>alert(1)</script><img src=x onerror=alert(1)>']],
            ['id' => 'd', 'type' => 'unknown-type', 'content' => []],
            ['id' => 'e', 'type' => 'hero', 'content' => ['title' => str_repeat('A', 500)], 'styles' => ['backgroundColor' => '#abc', 'textColor' => 'expression(x)']],
        ]]);

        $this->assertArrayNotHasKey('primary', $doc['theme']);
        $this->assertArrayNotHasKey('font', $doc['theme']);
        $this->assertCount(4, $doc['blocks']);
        $this->assertArrayNotHasKey('linkUrl', $doc['blocks'][0]['content']);
        $this->assertArrayNotHasKey('onclick', $doc['blocks'][0]['content']);
        $this->assertArrayNotHasKey('imageUrl', $doc['blocks'][1]['content']);
        $this->assertStringNotContainsString('script', $doc['blocks'][2]['content']['html']);
        $this->assertStringNotContainsString('onerror', $doc['blocks'][2]['content']['html']);
        $this->assertSame(160, mb_strlen($doc['blocks'][3]['content']['title']));
        $this->assertSame(['backgroundColor' => '#abc'], $doc['blocks'][3]['styles']);
    }

    public function test_draft_autosave_publish_and_restore_are_separate_from_the_live_page(): void
    {
        $shop = $this->shop();
        $page = $shop['page'];
        $url = "/api/v1/hellom/apps/landing-builder/site/pages/{$page->id}/document";

        $draft = $this->getJson($url, $this->auth($shop))->assertOk()->json('data');
        $draft['document']['blocks'][0]['content']['name'] = 'Nama Baru Belum Terbit';
        $saved = $this->putJson($url, ['document' => $draft['document'], 'revision' => $draft['revision']], $this->auth($shop))->assertOk()->json('data');
        // A stale tab (old revision) cannot overwrite newer work.
        $this->putJson($url, ['document' => $draft['document'], 'revision' => $draft['revision']], $this->auth($shop))->assertStatus(409)->assertJsonPath('error.code', 'DRAFT_CONFLICT');

        // The live page still shows the published version.
        $this->get('/' . $shop['username'])->assertOk()->assertSee('Toko Resep Bu Sari')->assertDontSee('Nama Baru Belum Terbit');

        $this->postJson("/api/v1/hellom/apps/landing-builder/site/pages/{$page->id}/publish", [], $this->auth($shop))->assertOk()->assertJsonPath('data.version_no', 2);
        $this->get('/' . $shop['username'])->assertOk()->assertSee('Nama Baru Belum Terbit');

        $history = $this->getJson("/api/v1/hellom/apps/landing-builder/site/pages/{$page->id}/history", $this->auth($shop))->assertOk()->json('data.items');
        $this->assertCount(2, $history);
        $v1 = collect($history)->firstWhere('version_no', 1)['id'];
        $restored = $this->postJson("/api/v1/hellom/apps/landing-builder/site/pages/{$page->id}/history/{$v1}/restore", [], $this->auth($shop))->assertOk()->json('data');
        $this->assertSame('Toko Resep Bu Sari', $restored['document']['blocks'][0]['content']['name']);
        $this->assertGreaterThan($saved['revision'], $restored['revision']);
    }

    public function test_public_page_is_server_rendered_with_meta_and_never_leaks_delivery_data(): void
    {
        $shop = $this->shop();
        $html = $this->get('/' . $shop['username'])->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="Toko Resep Bu Sari">', $html);
        $this->assertStringContainsString('<link rel="canonical"', $html);
        $this->assertStringContainsString('/beli/' . $shop['product']->public_id, $html);
        $this->assertStringContainsString('Rp 49.000', $html);
        $this->assertStringNotContainsString('1AbCdEfGhIjKlMnOpQrStUv', $html);
        $this->assertStringNotContainsString('rahasia', $html);
        $this->assertLessThan(300 * 1024, strlen($html));

        // Product page with its own URL and product meta.
        $product = $this->get('/' . $shop['username'] . '/' . $shop['product']->slug)->assertOk()->getContent();
        $this->assertStringContainsString('<meta property="og:type" content="product">', $product);
        $this->assertStringContainsString('<meta property="product:price:amount" content="49000">', $product);
        $this->assertStringNotContainsString('1AbCdEfGhIjKlMnOpQrStUv', $product);

        $this->get('/' . $shop['username'] . '/tidak-ada')->assertNotFound();
        // Reserved words stay with the React app.
        $this->get('/login')->assertOk()->assertHeaderMissing('ETag');
    }

    public function test_username_rules_redirect_and_suspended_shop(): void
    {
        $shop = $this->shop();
        $other = $this->seller();
        $put = fn (string $u) => $this->putJson('/api/v1/hellom/apps/landing-builder/site/username', ['username' => $u], $this->auth($shop));

        $put('admin')->assertStatus(422)->assertJsonValidationErrors('username');
        $put('Toko_Keren!')->assertStatus(422);
        $put($other['org']->slug)->assertStatus(422);
        $put('resep-bu-sari')->assertOk()->assertJsonPath('data.username', 'resep-bu-sari');

        $this->get('/' . $shop['username'])->assertRedirect('/resep-bu-sari');
        $this->get('/resep-bu-sari')->assertOk()->assertSee('Toko Resep Bu Sari');

        $shop['org']->forceFill(['landing_suspended_at' => now()])->save();
        $this->get('/resep-bu-sari')->assertStatus(410)->assertSee('Toko ini sedang nonaktif');
    }

    public function test_one_free_page_and_more_with_a_paid_plan(): void
    {
        $shop = $this->shop();
        $this->postJson('/api/v1/hellom/apps/landing-builder/site/pages', ['title' => 'Kelas Online'], $this->auth($shop))
            ->assertStatus(422)->assertJsonPath('error.code', 'PAGE_QUOTA');

        $plan = Plan::query()->forceCreate(['slug' => 'lp-pro-' . uniqid(), 'name' => 'Hellom Page Pro', 'type' => 'subscription', 'price' => 49000, 'is_active' => true, 'max_landing_pages' => 3]);
        $appId = (int) AppCatalog::query()->where('slug', 'landing_builder')->value('id');
        Entitlement::query()->create(['organization_id' => $shop['org']->id, 'app_id' => $appId, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth()]);

        $page = $this->postJson('/api/v1/hellom/apps/landing-builder/site/pages', ['title' => 'Kelas Online'], $this->auth($shop))->assertCreated()->json('data');
        $this->postJson("/api/v1/hellom/apps/landing-builder/site/pages/{$page['id']}/publish", [], $this->auth($shop))->assertOk();
        $this->get('/' . $shop['username'] . '/kelas-online')->assertOk();
        $this->getJson('/api/v1/hellom/apps/landing-builder/site', $this->auth($shop))->assertOk()->assertJsonPath('data.quota.pages', 3);

        // Plan ends → the extra page is no longer served.
        Entitlement::query()->where('organization_id', $shop['org']->id)->update(['ends_at' => now()->subDays(30)]);
        app(\App\Services\Landing\LandingShop::class)->bumpCache((int) $shop['org']->id);
        $this->get('/' . $shop['username'] . '/kelas-online')->assertNotFound();
        $this->get('/' . $shop['username'])->assertOk();
    }

    public function test_pixel_ids_are_validated_token_is_hidden_and_purchase_fires_once(): void
    {
        $shop = $this->shop();
        $url = '/api/v1/hellom/apps/landing-builder/tracking';
        $this->putJson($url, ['meta_pixel_id' => '123"><script>alert(1)</script>'], $this->auth($shop))->assertStatus(422)->assertJsonValidationErrors('meta_pixel_id');
        $token = str_repeat('EAAB', 20);
        $this->putJson($url, ['meta_pixel_id' => '1234567890123', 'ga4_id' => 'G-ABC1234', 'meta_capi_token' => $token], $this->auth($shop))
            ->assertOk()->assertJsonPath('data.meta_capi_token_set', true)->assertJsonMissingPath('data.meta_capi_token');
        $this->assertStringNotContainsString($token, (string) DB::table('landing_tracking_settings')->where('organization_id', $shop['org']->id)->value('meta_capi_token'));

        $html = $this->get('/' . $shop['username'])->assertOk()->getContent();
        $this->assertStringContainsString('1234567890123', $html);
        $this->assertStringNotContainsString($token, $html);
        // Pixels wait for the visitor's consent (banner on the shop page).
        $this->assertStringContainsString('id="hl-consent"', $html);

        // Paid order → one browser Purchase event, and server CAPI with the same event_id.
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        $order = LandingPageOrder::query()->create(['organization_id' => $shop['org']->id, 'product_id' => $shop['product']->id, 'product_kind' => 'drive',
            'product_name' => 'E-book Resep', 'amount' => 49000, 'net_amount' => 44000, 'buyer_name' => 'Rina Wati', 'buyer_email' => 'Rina@Example.test',
            'buyer_phone' => '081234567890', 'status' => 'paid', 'reference_id' => 'lps_TEST' . uniqid(), 'attribution' => ['fbclid' => 'abc123', 'consent' => 'denied']]);
        $order->forceFill(['paid_at' => now()])->save();

        $first = $this->postJson("/api/v1/hellom/public/landingpage/orders/{$order->reference_id}/purchase-event")->assertOk()->json('data');
        $this->assertTrue($first['fire']);
        $this->assertSame('purchase_' . $order->reference_id, $first['event_id']);
        $this->postJson("/api/v1/hellom/public/landingpage/orders/{$order->reference_id}/purchase-event")->assertOk()->assertJsonPath('data.fire', false);

        // No consent → nothing goes to Meta.
        (new SendMetaPurchaseEvent($order->id))->handle();
        Http::assertNothingSent();
        $order->forceFill(['attribution' => ['fbclid' => 'abc123', 'consent' => 'granted']])->save();
        (new SendMetaPurchaseEvent($order->id))->handle();
        Http::assertSent(function (HttpRequest $request) use ($order) {
            $event = $request->data()['data'][0];

            return str_contains($request->url(), '/1234567890123/events')
                && $event['event_id'] === 'purchase_' . $order->reference_id
                && $event['user_data']['em'][0] === hash('sha256', 'rina@example.test')
                && $event['custom_data']['value'] === 49000
                && !str_contains(json_encode($request->data()), 'Rina@Example.test');
        });
        $this->assertNotNull($order->fresh()->capi_sent_at);
    }

    public function test_stats_count_visits_once_per_day_and_report_sources_and_attribution(): void
    {
        $shop = $this->shop();
        $beacon = fn (array $extra) => $this->postJson('/api/v1/hellom/public/landing-events', $extra + ['username' => $shop['username']]);
        $beacon(['metric' => 'visit', 'page_id' => $shop['page']->id, 'source' => 'instagram.com'])->assertOk();
        $beacon(['metric' => 'visit', 'page_id' => $shop['page']->id, 'source' => 'instagram.com'])->assertOk(); // same visitor, same day
        $beacon(['metric' => 'click', 'page_id' => $shop['page']->id, 'dimension' => 'Beli E-book'])->assertOk();
        $beacon(['metric' => 'checkout_start', 'product_id' => $shop['product']->public_id])->assertOk();

        $this->fakeGatewayForCheckout();
        $this->postJson("/api/v1/hellom/public/landing-products/{$shop['product']->public_id}/checkout", [
            'buyer_name' => 'Sari', 'buyer_email' => 'sari@example.test', 'attribution' => ['utm_source' => 'Instagram', 'utm_campaign' => 'promo', 'evil' => 'x'],
        ])->assertCreated();
        $order = LandingPageOrder::query()->where('product_id', $shop['product']->id)->latest('id')->first();
        $this->assertSame(['utm_source' => 'Instagram', 'utm_campaign' => 'promo'], $order->attribution);
        $this->assertSame('instagram', $order->source);

        $report = $this->getJson('/api/v1/hellom/apps/landing-builder/stats/traffic?days=7', $this->auth($shop))->assertOk()->json('data');
        $this->assertSame(1, $report['totals']['visits']);
        $this->assertSame(1, $report['totals']['clicks']);
        $this->assertSame('instagram', $report['sources'][0]['source']);
        $this->assertSame('Beli E-book', $report['clicks'][0]['label']);
        $this->assertSame(1, $report['products'][0]['checkout_starts']);
    }

    public function test_old_pages_are_converted_and_inline_images_become_files(): void
    {
        $seller = $this->seller();
        $page = OrganizationLandingPage::query()->findOrFail($seller['page']->id);
        $png = 'data:image/png;base64,' . base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        \App\Models\LandingBlock::query()->create(['organization_id' => $seller['org']->id, 'landing_page_id' => $page->id, 'block_key' => 'img', 'block_type' => 'image',
            'sort_order' => 5, 'is_visible' => true, 'content' => ['imageUrl' => $png]]);

        $this->artisan('landing:documents-backfill', ['--force' => true])->assertSuccessful();
        $page->refresh();
        $this->assertNotNull($page->published_version_id);
        $image = collect($page->draft_document['blocks'])->firstWhere('type', 'image');
        $this->assertStringStartsWith('/media/landing-builder/', $image['content']['imageUrl']);
        Storage::disk('public')->assertExists(substr($image['content']['imageUrl'], strlen('/media/')));
        // The old inline product (with its secret fileUrl) keeps no delivery link in the document.
        $this->assertStringNotContainsString('rahasia', json_encode($page->draft_document));
    }

    private function fakeGatewayForCheckout(): void
    {
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake(['*/api/v2/payment' => Http::response(['Status' => 200, 'Data' => ['SessionID' => 's', 'Url' => 'https://sandbox.ipaymu.com/pay/s']])]);
    }
}
