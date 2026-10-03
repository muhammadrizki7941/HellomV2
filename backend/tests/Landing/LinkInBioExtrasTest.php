<?php

namespace Tests\Landing;

use App\Http\Middleware\Api\EnsureAppEntitlement;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Landing\LandingDocumentService;
use App\Services\Landing\LandingShop;
use App\Services\Landing\TemplateLibrary;
use App\Support\Landing\BlockSchema;
use App\Support\Landing\Embed;
use App\Support\Landing\Fonts;
use App\Support\Landing\ThemeStyle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Fase 7: header banner, animations, YouTube auto-preview, premium templates (+ super admin). */
class LinkInBioExtrasTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/v1/hellom/apps/landing-builder';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAppEntitlement::class);
        Http::preventStrayRequests();
    }

    private function publish(array $seller, array $document): string
    {
        $seller['org']->forceFill(['landing_username' => 'extra' . $seller['org']->id])->save();
        $documents = app(LandingDocumentService::class);
        $documents->saveDraft($seller['page']->fresh(), $document, null);
        $documents->publish($seller['page']->fresh(), $seller['user']);
        app(LandingShop::class)->bumpCache((int) $seller['org']->id);

        return $this->get('/extra' . $seller['org']->id)->assertOk()->getContent();
    }

    private function superAdminToken(): string
    {
        $user = User::query()->create(['name' => 'Super', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'super_admin']);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);

        return $plain;
    }

    public function test_youtube_links_of_every_shape_are_recognised(): void
    {
        $cases = [
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ', false, 0],
            ['https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=90', 'dQw4w9WgXcQ', false, 90],
            ['https://youtu.be/dQw4w9WgXcQ?si=abc&t=1m30s', 'dQw4w9WgXcQ', false, 90],
            ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ', false, 0],
            ['https://youtube.com/shorts/abcdefghijk?feature=share', 'abcdefghijk', true, 0],
            ['https://music.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ', false, 0],
            ['https://www.youtube.com/live/dQw4w9WgXcQ', 'dQw4w9WgXcQ', false, 0],
        ];
        foreach ($cases as [$url, $id, $vertical, $start]) {
            $e = Embed::resolve($url);
            $this->assertSame(['youtube', $id, $vertical, $start], [$e['provider'] ?? null, $e['id'] ?? null, $e['vertical'] ?? null, $e['start'] ?? null], $url);
        }
        foreach (['https://evil.example/youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=short', 'javascript:alert(1)'] as $bad) {
            $this->assertNull(Embed::resolve($bad), $bad);
        }
    }

    public function test_video_preview_endpoint_reads_the_title_and_explains_bad_links(): void
    {
        Http::fake([
            'www.youtube.com/oembed*' => fn ($request) => str_contains($request->url(), 'deadvideo00')
                ? Http::response('Not Found', 404)
                : Http::response(['title' => 'Resep Kue Lumpur', 'author_name' => 'Dapur Ani'], 200),
        ]);
        $seller = $this->seller();
        $auth = ['Authorization' => 'Bearer ' . $seller['token']];

        $this->getJson(self::BASE . '/video-preview?url=' . urlencode('https://youtube.com/shorts/abcdefghijk'), $auth)->assertOk()
            ->assertJsonPath('data.provider', 'youtube')->assertJsonPath('data.vertical', true)
            ->assertJsonPath('data.title', 'Resep Kue Lumpur')->assertJsonPath('data.label', 'YouTube Shorts');
        $this->getJson(self::BASE . '/video-preview?url=' . urlencode('https://youtu.be/deadvideo00'), $auth)->assertStatus(422)->assertJsonPath('error.code', 'VIDEO_NOT_FOUND');
        $this->getJson(self::BASE . '/video-preview?url=' . urlencode('https://vimeo.com/123'), $auth)->assertStatus(422)
            ->assertJsonPath('message', 'Link video belum dikenali. Tempel link YouTube (termasuk Shorts), TikTok, atau Instagram Reels.');
        $this->getJson(self::BASE . '/video-preview?url=' . urlencode('https://www.instagram.com/reel/Cabc123XYZ/'), $auth)->assertOk()
            ->assertJsonPath('data.provider', 'instagram')->assertJsonPath('data.vertical', true);
        // Only the rebuilt watch URL ever went to YouTube.
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.youtube.com/oembed') && str_contains(urldecode($request->url()), 'https://www.youtube.com/watch?v='));
    }

    public function test_banner_video_options_and_animations_render(): void
    {
        $html = $this->publish($this->seller(), [
            'theme' => ['motion' => ['entrance' => 'slide', 'speed' => 'fast', 'stagger' => true], 'bg' => ['type' => 'animated', 'animation' => 'waves', 'color' => '#111111', 'from' => '#f97316', 'to' => '#7c2d12']],
            'blocks' => [
                ['id' => 'p1', 'type' => 'profile', 'content' => ['name' => 'Senja Kala', 'coverVideo' => 'https://youtu.be/dQw4w9WgXcQ?t=15', 'coverRatio' => 'square', 'coverFocusX' => 20, 'coverFocusY' => 80, 'coverFade' => true, 'avatarPosition' => 'below']],
                ['id' => 'v1', 'type' => 'video', 'content' => ['videoUrl' => 'https://youtube.com/shorts/abcdefghijk?t=5', 'title' => 'Klip', 'hideTitle' => true, 'autoplay' => true, 'corners' => 'square']],
                ['id' => 'v2', 'type' => 'video', 'content' => ['videoUrl' => 'https://www.instagram.com/reel/Cabc123XYZ/', 'title' => 'Reels']],
                ['id' => 'b1', 'type' => 'button', 'content' => ['text' => 'Tiket', 'linkUrl' => 'https://example.com', 'featured' => true, 'featuredStyle' => 'glow'], 'styles' => ['entrance' => 'none']],
            ],
        ]);
        // Banner: YouTube thumbnail now, muted player later; ratio, focus, fade, avatar below.
        $this->assertStringContainsString('class="profile blk', $html);
        $this->assertMatchesRegularExpression('~class="profile[^"]*has-cover av-below"~', $html);
        $this->assertStringContainsString('aspect-ratio:1/1', $html);
        $this->assertStringContainsString('src="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg"', $html);
        $this->assertStringContainsString('object-position:20% 80%', $html);
        $this->assertMatchesRegularExpression('~data-cover-yt="dQw4w9WgXcQ"\s+data-start="15"~', $html);
        $this->assertStringContainsString('class="cover-fade"', $html);
        // Shorts: vertical facade, start time, autoplay, square corners, title hidden.
        $this->assertMatchesRegularExpression('~class="yt vertical square" data-yt="abcdefghijk"\s+data-start="5"\s+data-autoplay="1"~', $html);
        $this->assertStringNotContainsString('<h2 class="center">Klip</h2>', $html);
        $this->assertStringContainsString('src="https://www.instagram.com/reel/Cabc123XYZ/embed"', $html);
        // Animations: entrance wrappers with order, per-block "none", featured glow, waves background.
        $this->assertMatchesRegularExpression('~<div class="ent ent-slide" style="--i:0">\s*<section class="profile~', $html);
        $this->assertStringContainsString('--ent-dur:350ms;--ent-step:80ms', $html);
        $this->assertStringContainsString('btn featured fx-glow', $html);
        $this->assertDoesNotMatchRegularExpression('~<div class="ent[^"]*"[^>]*>\s*<section class="blk center"[^>]*>\s*<div class="wrap">\s*<a class="btn featured~', $html);
        $this->assertStringContainsString('class="bg-layer bg-waves"', $html);

        // "Matikan semua animasi": no entrance wrappers, body class no-anim.
        $html = $this->publish($this->seller(), ['theme' => ['motion' => ['entrance' => 'zoom', 'off' => true]], 'blocks' => [['id' => 'p1', 'type' => 'profile', 'content' => ['name' => 'Tenang']]]]);
        $this->assertMatchesRegularExpression('~<body class="[^"]*no-anim~', $html);
        $this->assertStringNotContainsString('class="ent ', $html);
    }

    public function test_every_template_is_valid_readable_and_complete(): void
    {
        $library = app(TemplateLibrary::class);
        $raw = $library->raw();
        $this->assertGreaterThanOrEqual(12, count($raw));
        foreach ($raw as $id => $template) {
            $this->assertArrayHasKey($template['category'], TemplateLibrary::CATEGORIES, $id);
            preg_match_all('/\{\{slot:([a-z0-9_-]+)\}\}/', (string) json_encode($template['document']), $m);
            $this->assertSame([], array_values(array_diff(array_unique($m[1]), array_keys($template['slots']))), "{$id}: placeholder without a slot");
            $doc = $library->document($id);
            $this->assertCount(count($template['document']['blocks']), $doc['blocks'], "{$id}: a block was dropped by the schema");
            $this->assertTrue(Fonts::valid($doc['theme']['headingFont'] ?? null) && Fonts::valid($doc['theme']['bodyFont'] ?? null), "{$id}: fonts");

            // WCAG AA (4.5:1) for page text and every button look the template uses.
            $t = ThemeStyle::resolve($doc['theme']);
            $this->assertFalse($t['textAdjusted'], "{$id}: text colour had to be corrected");
            $this->assertGreaterThanOrEqual(4.5, ThemeStyle::contrast($t['text'], $t['effectiveBackground']), "{$id}: text");
            $fills = array_unique(array_merge([$t['button']['fill']], array_filter(array_map(fn ($b) => $b['type'] === 'button' ? ($b['content']['fill'] ?? null) : null, $doc['blocks']))));
            foreach ($fills as $fill) {
                [$fg, $bg] = match ($fill) {
                    'solid' => [$t['buttonText'], $t['primary']],
                    'outline' => [ThemeStyle::contrast($t['primary'], $t['effectiveBackground']) >= 3 ? $t['primary'] : $t['text'], $t['effectiveBackground']],
                    default => [$t['text'], $t['effectiveBackground']],
                };
                $this->assertGreaterThanOrEqual(4.5, ThemeStyle::contrast($fg, $bg), "{$id}: {$fill} button");
            }
        }
    }

    public function test_super_admin_sets_images_visibility_and_order_sellers_follow(): void
    {
        Storage::fake('public');
        $seller = $this->seller();
        $sellerAuth = ['Authorization' => 'Bearer ' . $seller['token']];
        $admin = ['Authorization' => 'Bearer ' . $this->superAdminToken()];

        // Sellers cannot manage templates.
        $this->getJson('/api/v1/hellom/admin/landing-templates', $sellerAuth)->assertForbidden();

        $list = $this->getJson('/api/v1/hellom/admin/landing-templates', $admin)->assertOk()->json('data.templates');
        $ids = array_column($list, 'id');
        $this->assertContains('dapur-hangat', $ids);

        // Image for a slot → used in the seller's template document (WebP, our media path).
        $this->post('/api/v1/hellom/admin/landing-templates/dapur-hangat/images/banner', ['file' => UploadedFile::fake()->image('nasi.jpg', 1600, 900)], $admin + ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/v1/hellom/admin/landing-templates/dapur-hangat/images/tidak-ada', ['file' => UploadedFile::fake()->image('x.jpg')], $admin + ['Accept' => 'application/json'])->assertNotFound();
        $url = collect(collect($this->getJson('/api/v1/hellom/admin/landing-templates', $admin)->json('data.templates'))->firstWhere('id', 'dapur-hangat')['slots'])->firstWhere('key', 'banner')['url'];
        $this->assertMatchesRegularExpression('~^/media/landing-templates/.+\.webp$~', (string) $url);

        // Hide one, move another to the top.
        $order = array_values(array_diff($ids, ['kelas-online']));
        array_unshift($order, 'kelas-online');
        $this->putJson('/api/v1/hellom/admin/landing-templates', ['order' => $order, 'hidden' => ['neo-brutal']], $admin)->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'like', '%landing_templates.layout_updated')->exists());

        $seen = $this->getJson(self::BASE . '/page-templates', $sellerAuth)->assertOk()->json('data.templates');
        $this->assertSame('kelas-online', $seen[0]['id']);
        $this->assertNotContains('neo-brutal', array_column($seen, 'id'));
        $dapur = collect($seen)->firstWhere('id', 'dapur-hangat');
        $this->assertSame($url, collect($dapur['document']['blocks'])->firstWhere('type', 'profile')['content']['coverUrl']);
        // Empty slot → no picture at all (initial avatar).
        $this->assertEmpty(collect($dapur['document']['blocks'])->firstWhere('type', 'profile')['content']['avatarUrl'] ?? '');

        // Live preview with the shop's data; hidden templates cannot be previewed.
        $html = $this->postJson(self::BASE . '/page-templates/dapur-hangat/preview', [], $sellerAuth)->assertOk()->json('data.html');
        $this->assertStringContainsString('Dapur Bu Ratna', $html);
        $this->assertStringNotContainsString('ketuk untuk', $html);
        $this->assertStringContainsString('Pesan via WhatsApp', $html); // shown even without a number in the preview
        $this->postJson(self::BASE . '/page-templates/neo-brutal/preview', [], $sellerAuth)->assertNotFound();

        $this->deleteJson('/api/v1/hellom/admin/landing-templates/dapur-hangat/images/banner', [], $admin)->assertOk();
        $this->assertFalse(Storage::disk('public')->exists(substr((string) $url, strlen('/media/'))));
    }
}
