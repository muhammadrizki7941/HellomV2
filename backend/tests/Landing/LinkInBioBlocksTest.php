<?php

namespace Tests\Landing;

use App\Http\Middleware\Api\EnsureAppEntitlement;
use App\Services\Landing\LandingDocumentService;
use App\Support\Landing\BlockSchema;
use App\Support\Landing\Embed;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/** Fase 3 blocks: spacer, WhatsApp, embed (Spotify / TikTok / Instagram / YouTube), TikTok in the video block. */
class LinkInBioBlocksTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAppEntitlement::class);
    }

    public function test_embed_links_become_official_iframes_only(): void
    {
        $spotify = Embed::resolve('https://open.spotify.com/intl-id/track/4uLU6hMCjMI75M1A2tKUQC?si=abc');
        $this->assertSame(['spotify', 'https://open.spotify.com/embed/track/4uLU6hMCjMI75M1A2tKUQC', 152], [$spotify['provider'], $spotify['src'], $spotify['height']]);
        $this->assertSame(352, Embed::resolve('https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M')['height']);

        $tiktok = Embed::resolve('https://www.tiktok.com/@toko.kue/video/7312345678901234567?lang=id');
        $this->assertSame(['tiktok', 'https://www.tiktok.com/embed/v2/7312345678901234567', true], [$tiktok['provider'], $tiktok['src'], $tiktok['vertical']]);

        $reel = Embed::resolve('https://www.instagram.com/reel/C1aBcD2eFgH/?igsh=xyz');
        $this->assertSame('https://www.instagram.com/reel/C1aBcD2eFgH/embed', $reel['src']);
        $this->assertSame('https://www.instagram.com/p/C1aBcD2eFgH/embed', Embed::resolve('https://instagram.com/p/C1aBcD2eFgH')['src']);

        $shorts = Embed::resolve('https://youtube.com/shorts/dQw4w9WgXcQ');
        $this->assertSame(['youtube', 'dQw4w9WgXcQ', true], [$shorts['provider'], $shorts['id'], $shorts['vertical']]);

        foreach (['https://evil.example/embed', 'javascript:alert(1)', 'https://open.spotify.com.evil.tld/track/4uLU6hMCjMI75M1A2tKUQC', ''] as $bad) {
            $this->assertNull(Embed::resolve($bad), $bad);
        }
    }

    public function test_new_blocks_render_on_the_public_page(): void
    {
        $seller = $this->seller();
        $seller['org']->forceFill(['landing_username' => 'blok' . $seller['org']->id])->save();
        $documents = app(LandingDocumentService::class);
        $documents->saveDraft($seller['page'], [
            'settings' => ['whatsappNumber' => '081234567890'],
            'blocks' => [
                ['id' => 's1', 'type' => 'spacer', 'content' => ['height' => 48]],
                ['id' => 'w1', 'type' => 'whatsapp', 'content' => ['text' => 'Tanya stok', 'message' => 'Halo, mau tanya', 'style' => 'card', 'title' => 'Ada pertanyaan?']],
                ['id' => 'e1', 'type' => 'embed', 'content' => ['url' => 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M', 'title' => 'Playlist toko']],
                ['id' => 'e2', 'type' => 'embed', 'content' => ['url' => 'https://evil.example/frame']],
                ['id' => 'v1', 'type' => 'video', 'content' => ['videoUrl' => 'https://www.tiktok.com/@toko.kue/video/7312345678901234567']],
                ['id' => 'p1', 'type' => 'product', 'content' => ['kind' => 'physical', 'buttonText' => 'Beli']],
            ],
        ], null);
        $documents->publish($seller['page']->fresh(), $seller['user']);

        $response = $this->get('/blok' . $seller['org']->id)->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('style="height:48px"', $html);
        // WhatsApp falls back to the page's number (0812… → 62812…) with the message.
        $this->assertStringContainsString('https://wa.me/6281234567890?text=Halo%2C%20mau%20tanya', $html);
        $this->assertStringContainsString('Ada pertanyaan?', $html);
        $this->assertStringContainsString('src="https://open.spotify.com/embed/playlist/37i9dQZF1DXcBWIGoYBM5M"', $html);
        $this->assertStringContainsString('src="https://www.tiktok.com/embed/v2/7312345678901234567"', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        // The page CSP lets exactly these embeds load.
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression('~frame-src [^;]*https://open\.spotify\.com[^;]*https://www\.tiktok\.com[^;]*https://www\.instagram\.com~', $csp);

        // Schema keeps the picker hint and the new fields, drops the rest.
        $doc = BlockSchema::normalize(['blocks' => [['id' => 'x', 'type' => 'spacer', 'content' => ['height' => 999, 'evil' => 1]], ['id' => 'y', 'type' => 'product', 'content' => ['kind' => 'other']]]]);
        $this->assertSame(['height' => 160], $doc['blocks'][0]['content']);
        $this->assertSame([], $doc['blocks'][1]['content']);
    }
}
