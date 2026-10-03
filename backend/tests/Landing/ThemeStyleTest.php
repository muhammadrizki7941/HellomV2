<?php

namespace Tests\Landing;

use App\Http\Middleware\Api\EnsureAppEntitlement;
use App\Services\Landing\LandingDocumentService;
use App\Services\Landing\LandingShop;
use App\Support\Landing\BlockSchema;
use App\Support\Landing\ThemeStyle;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/** Fase 5: backgrounds, fonts, button styles (theme + single button), schema v3 migration. */
class ThemeStyleTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAppEntitlement::class);
    }

    public function test_v2_theme_moves_into_v3_without_changing_the_look(): void
    {
        $doc = BlockSchema::normalize(['schema_version' => 2,
            'theme' => ['preset' => 'luxury', 'font' => 'mono', 'buttonShape' => 'square', 'buttonStyle' => 'outline'],
            'blocks' => [
                ['id' => 'b1', 'type' => 'button', 'content' => ['text' => 'Pesan', 'style' => 'solid']],
                ['id' => 'b2', 'type' => 'button', 'content' => ['text' => 'Info', 'style' => 'outline']],
            ]]);

        $this->assertSame(3, $doc['schema_version']);
        $this->assertSame(['preset' => 'luxury', 'headingFont' => 'mono', 'bodyFont' => 'mono', 'button' => ['shape' => 'square', 'fill' => 'outline']], $doc['theme']);
        // A button's own choice stays: solid on an outline theme stays solid.
        $this->assertSame('solid', $doc['blocks'][0]['content']['fill']);
        $this->assertSame('outline', $doc['blocks'][1]['content']['fill']);
        $this->assertArrayNotHasKey('style', $doc['blocks'][0]['content']);

        // Unknown fonts / icons / background types are dropped.
        $clean = BlockSchema::normalize(['theme' => ['headingFont' => 'comic', 'bg' => ['type' => 'video', 'color' => '#123456'], 'button' => ['hover' => 'spin']],
            'blocks' => [['id' => 'x', 'type' => 'button', 'content' => ['icon' => 'skull', 'featured' => true]]]]);
        $this->assertSame(['bg' => ['color' => '#123456'], 'button' => []], $clean['theme']);
        $this->assertSame(['featured' => true], $clean['blocks'][0]['content']);
    }

    public function test_backgrounds_fonts_and_readable_text(): void
    {
        $gradient = ThemeStyle::resolve(['bg' => ['type' => 'gradient', 'from' => '#0f172a', 'via' => '#7c3aed', 'to' => '#db2777', 'angle' => 135], 'text' => '#111111']);
        $this->assertStringContainsString('linear-gradient(135deg,#0f172a,#7c3aed,#db2777)', $gradient['bgCss']);
        $this->assertSame('#ffffff', $gradient['text']);          // dark text on a dark gradient → white
        $this->assertTrue($gradient['textAdjusted']);
        $this->assertTrue($gradient['dark']);

        $image = ThemeStyle::resolve(['bg' => ['type' => 'image', 'image' => '/media/landing-builder/1/foto.webp', 'overlay' => 0.6, 'blur' => 6, 'position' => 'top']]);
        $this->assertStringContainsString("background-image:url('/media/landing-builder/1/foto.webp');background-position:center top;filter:blur(6px)", $image['bgLayers']);
        $this->assertStringContainsString('rgba(0,0,0,0.6)', $image['bgLayers']);
        $this->assertSame('#ffffff', $image['text']);
        $this->assertStringNotContainsString('javascript', ThemeStyle::resolve(['bg' => ['type' => 'image', 'image' => 'javascript:alert(1)']])['bgLayers']);

        foreach (['dots', 'grid', 'diagonal', 'checks', 'waves', 'plus'] as $pattern) {
            $style = ThemeStyle::resolve(['bg' => ['type' => 'pattern', 'pattern' => $pattern, 'color' => '#fef3c7']]);
            $this->assertStringContainsString('background-color:#fef3c7;background-image:', $style['bgCss'], $pattern);
        }
        foreach (['aurora' => 'bg-aurora', 'blobs' => 'bg-blobs', 'particles' => 'bg-particles'] as $animation => $class) {
            $style = ThemeStyle::resolve(['bg' => ['type' => 'animated', 'animation' => $animation, 'from' => '#22d3ee', 'to' => '#a855f7']]);
            $this->assertStringContainsString($class, $style['bgLayers']);
            $this->assertStringContainsString('anim-' . $animation, $style['bgClass']);
        }

        $fonts = ThemeStyle::resolve(['headingFont' => 'playfair', 'bodyFont' => 'jakarta']);
        $this->assertStringContainsString('"Playfair Display"', $fonts['headingFont']);
        $this->assertStringContainsString('src:url(/fonts/landing/playfair.woff2)', $fonts['fontFaces']);
        $this->assertStringContainsString('src:url(/fonts/landing/jakarta.woff2)', $fonts['fontFaces']);
        $this->assertSame('/fonts/landing/playfair.woff2', $fonts['fontPreload']);
        $this->assertSame('', ThemeStyle::resolve([])['fontFaces']); // system fonts: nothing to load
    }

    public function test_button_styles_for_the_theme_and_one_button(): void
    {
        $t = ThemeStyle::resolve(['preset' => 'minimal', 'button' => ['shape' => 'pill', 'fill' => 'solid', 'shadow' => 'hard', 'borderWidth' => 3, 'hover' => 'lift']]);
        $this->assertStringContainsString('--btn-shadow:5px 5px 0 #000000', $t['buttonVars']);
        $this->assertStringContainsString('--btn-border:#000000', $t['buttonVars']); // neo-brutalism outline
        $this->assertStringContainsString('--radius:999px', $t['buttonVars']);
        $this->assertSame('lift', $t['hover']);
        $glass = ThemeStyle::buttonVars($t, 'glass', 'square', 'soft');
        $this->assertStringContainsString('--btn-blur:blur(12px)', $glass);
        $this->assertStringContainsString('--radius:4px', $glass);
        $this->assertStringContainsString('--btn-shadow:0 8px 24px', $glass);
        // Outline on a background where the brand color is unreadable falls back to the text color.
        $pale = ThemeStyle::resolve(['background' => '#ffffff', 'primary' => '#fde68a', 'text' => '#18181b', 'button' => ['fill' => 'outline']]);
        $this->assertStringContainsString('--btn-fg:#18181b', $pale['buttonVars']);

        // Rendered page: background on <html>, font preload, button vars, own look, icon, featured.
        $seller = $this->seller();
        $seller['org']->forceFill(['landing_username' => 'tema' . $seller['org']->id])->save();
        $documents = app(LandingDocumentService::class);
        $documents->saveDraft($seller['page'], [
            'theme' => ['preset' => 'industrial', 'headingFont' => 'bebas', 'bodyFont' => 'inter', 'bg' => ['type' => 'gradient', 'from' => '#fde68a', 'to' => '#fca5a5'],
                'button' => ['fill' => 'solid', 'hover' => 'shine']],
            'blocks' => [
                ['id' => 'b1', 'type' => 'button', 'content' => ['text' => 'Order sekarang', 'linkUrl' => 'https://example.com', 'icon' => 'bag', 'featured' => true, 'fill' => 'outline', 'shape' => 'square']],
                ['id' => 'b2', 'type' => 'button', 'content' => ['text' => 'Katalog', 'linkUrl' => 'https://example.com/k', 'thumbUrl' => '/media/landing-builder/1/t.webp']],
            ]], null);
        $documents->publish($seller['page']->fresh(), $seller['user']);
        app(LandingShop::class)->bumpCache((int) $seller['org']->id);
        $html = $this->get('/tema' . $seller['org']->id)->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="id" style="background:#fde68a;background-image:linear-gradient(160deg,#fde68a,#fca5a5)', $html);
        $this->assertStringContainsString('<link rel="preload" href="/fonts/landing/bebas.woff2" as="font"', $html);
        $this->assertMatchesRegularExpression('~<body class="bg-gradient hv-shine">~', $html);
        $this->assertMatchesRegularExpression('~class="btn featured has-ico"\s+style="--btn-bg:transparent;[^"]*--radius:4px"~', $html);
        $this->assertStringContainsString('<span class="btn-ico"><svg viewBox="0 0 24 24"', $html);
        $this->assertStringContainsString('<span class="btn-ico"><img src="/media/landing-builder/1/t.webp" alt=""', $html);

        // Fonts are served (dev / e2e) with CORS for the sandboxed editor preview.
        $this->get('/fonts/landing/inter.woff2')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        // Only *.woff2 names match the route; anything else is the app shell, never a file from disk.
        $this->get('/fonts/landing/..%2F..%2F.env')->assertDontSee('APP_KEY')->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
