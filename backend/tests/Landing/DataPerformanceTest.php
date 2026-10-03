<?php

namespace Tests\Landing;

use App\Http\Middleware\Api\EnsureAppEntitlement;
use App\Services\Landing\LandingDocumentService;
use App\Services\Landing\LandingShop;
use App\Support\Landing\OgImage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Fase 6: clicks per link + source, generated share card, tenant isolation of the builder data. */
class DataPerformanceTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/v1/hellom/apps/landing-builder';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAppEntitlement::class);
    }

    /** @return array<string, mixed> seller with a published link-in-bio page */
    private function shop(string $name = 'Toko Kue Bu Ani', array $theme = []): array
    {
        $seller = $this->seller();
        $seller['org']->forceFill(['landing_username' => 'data' . $seller['org']->id])->save();
        $documents = app(LandingDocumentService::class);
        $documents->saveDraft($seller['page']->fresh(), [
            'theme' => $theme,
            'social' => ['items' => [['platform' => 'instagram', 'value' => '@toko.kue']]],
            'settings' => ['showFloatingWhatsapp' => true, 'whatsappNumber' => '081234567890'],
            'blocks' => [
                ['id' => 'p1', 'type' => 'profile', 'content' => ['name' => $name, 'bio' => 'Kue rumahan tanpa pengawet']],
                ['id' => 'b1', 'type' => 'button', 'content' => ['text' => 'Pesan sekarang', 'actionType' => 'link', 'linkUrl' => 'https://example.com/pesan']],
            ],
        ], null);
        $documents->publish($seller['page']->fresh(), $seller['user']);
        app(LandingShop::class)->bumpCache((int) $seller['org']->id);
        $seller['username'] = $seller['org']->landingUsername();

        return $seller;
    }

    private function event(array $body, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/hellom/public/landing-events', $body)->assertOk();
    }

    public function test_clicks_are_counted_per_link_and_source(): void
    {
        $seller = $this->shop();
        $html = $this->get('/' . $seller['username'] . '/toko')->assertOk()->getContent();
        $this->assertStringContainsString('data-item="b1" data-track="click"', $html);
        $this->assertStringContainsString('data-item="social:instagram"', $html);
        $this->assertStringContainsString('data-item="wa-float"', $html);

        $u = $seller['username'];
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4'] as $ip) {
            $this->event(['username' => $u, 'metric' => 'visit', 'source' => 'https://l.instagram.com/'], $ip);
        }
        $this->event(['username' => $u, 'metric' => 'click', 'item' => 'b1', 'dimension' => 'Pesan sekarang', 'source' => 'https://l.instagram.com/']);
        $this->event(['username' => $u, 'metric' => 'click', 'item' => 'b1', 'dimension' => 'Pesan sekarang', 'source' => 'instagram']);
        $this->event(['username' => $u, 'metric' => 'click', 'item' => 'b1', 'dimension' => 'Pesan sekarang', 'source' => '']);
        $this->event(['username' => $u, 'metric' => 'click', 'item' => 'social:instagram', 'dimension' => 'Instagram', 'source' => 'tiktok']);
        $this->event(['username' => $u, 'metric' => 'click', 'item' => '"><script>', 'dimension' => 'Aneh']); // unknown key → no link row

        $report = $this->getJson(self::BASE . '/stats/traffic?days=7', ['Authorization' => 'Bearer ' . $seller['token']])->assertOk()->json('data');
        $this->assertSame(4, $report['totals']['visits']);
        $this->assertSame(5, $report['totals']['clicks']);
        $this->assertCount(2, $report['links']);
        [$button, $social] = $report['links'];
        $this->assertSame(['b1', 'Pesan sekarang', 'block', 3, 75], [$button['item'], $button['label'], $button['kind'], $button['clicks'], (int) $button['ctr']]);
        $this->assertSame([['source' => 'instagram', 'clicks' => 2], ['source' => 'langsung', 'clicks' => 1]], $button['sources']);
        $this->assertSame(['social:instagram', 'social', 1, [['source' => 'tiktok', 'clicks' => 1]]], [$social['item'], $social['kind'], $social['clicks'], $social['sources']]);
        $this->assertSame(1, (int) DB::table('landing_stats_daily')->where('organization_id', $seller['org']->id)->where('dimension', 'Aneh')->where('item', '')->sum('count'));
    }

    public function test_stats_and_builder_data_stay_inside_the_shop(): void
    {
        $a = $this->shop('Toko A');
        $b = $this->shop('Toko B');

        // An event naming shop A with shop B's page id is counted for A without a page.
        $this->event(['username' => $a['username'], 'metric' => 'click', 'item' => 'b1', 'page_id' => $b['page']->id, 'dimension' => 'Pesan sekarang']);
        $row = DB::table('landing_stats_daily')->where('item', 'b1')->where('organization_id', $a['org']->id)->first();
        $this->assertSame(0, (int) $row->landing_page_id);
        $this->assertSame([], $this->getJson(self::BASE . '/stats/traffic', ['Authorization' => 'Bearer ' . $b['token']])->json('data.links'));

        // Seller A cannot read, render or publish shop B's page.
        $auth = ['Authorization' => 'Bearer ' . $a['token']];
        $this->getJson(self::BASE . "/site/pages/{$b['page']->id}/document", $auth)->assertNotFound();
        $this->postJson(self::BASE . "/site/pages/{$b['page']->id}/render", ['document' => ['blocks' => []]], $auth)->assertNotFound();
        $this->postJson(self::BASE . "/site/pages/{$b['page']->id}/publish", [], $auth)->assertNotFound();
    }

    public function test_share_card_is_generated_cached_and_replaceable(): void
    {
        if (!OgImage::supported()) {
            $this->markTestSkipped('GD with FreeType is not available');
        }
        Storage::fake('local');
        $seller = $this->shop('Toko Kue Bu Ani', ['primary' => '#db2777', 'bg' => ['type' => 'gradient', 'from' => '#fce7f3', 'to' => '#e0e7ff']]);
        $html = $this->get('/' . $seller['username'] . '/toko')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<meta property="og:image" content="[^"]*/og/' . $seller['username'] . '/(?:_|toko)-([a-f0-9]{12})">~', $html);
        preg_match('~/og/' . $seller['username'] . '/((?:_|toko)-[a-f0-9]{12})~', $html, $m);
        $card = '/og/' . $seller['username'] . '/' . $m[1];

        $response = $this->get($card)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $size = getimagesize($response->baseResponse->getFile()->getPathname());
        $this->assertSame([1200, 630], [$size[0], $size[1]]);
        $this->assertCount(1, Storage::disk('local')->files('og/' . $seller['org']->id));

        // Old / made-up hash → the current card; unknown shop → 404.
        $this->get('/og/' . $seller['username'] . '/_-000000000000')->assertRedirect($card);
        $this->get('/og/tidak-ada-toko/toko-000000000000')->assertNotFound();

        // Page list shows the card; the seller's own picture replaces it in og:image.
        $auth = ['Authorization' => 'Bearer ' . $seller['token']];
        $page = collect($this->getJson(self::BASE . '/site', $auth)->json('data.pages'))->firstWhere('id', $seller['page']->id);
        $this->assertStringEndsWith($card, (string) $page['share_card']);
        $this->patchJson(self::BASE . "/site/pages/{$seller['page']->id}", ['seo_image' => '/media/landing/sendiri.webp'], $auth)->assertOk();
        $html = $this->get('/' . $seller['username'] . '/toko')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<meta property="og:image" content="[^"]*/media/landing/sendiri\.webp">~', $html);
    }
}
