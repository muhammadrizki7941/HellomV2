<?php

namespace Tests\Landing;

use App\Http\Middleware\Api\EnsureAppEntitlement;
use App\Services\Landing\LandingDocumentService;
use App\Support\Landing\BlockSchema;
use App\Support\Landing\SocialLinks;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/** Fase 4: the social media panel (page-level, optional), 18 platforms, links rebuilt on the server. */
class SocialPanelTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAppEntitlement::class);
    }

    public function test_usernames_and_links_become_profile_urls(): void
    {
        $cases = [
            ['instagram', '@toko.kue', 'https://www.instagram.com/toko.kue'],
            ['instagram', 'https://www.instagram.com/toko.kue/?hl=id', 'https://www.instagram.com/toko.kue'],
            ['tiktok', 'https://www.tiktok.com/@toko_kue?lang=id', 'https://www.tiktok.com/@toko_kue'],
            ['youtube', 'tokokue', 'https://www.youtube.com/@tokokue'],
            ['youtube', 'https://www.youtube.com/channel/UC1234567890abcdefghijkl', 'https://www.youtube.com/channel/UC1234567890abcdefghijkl'],
            ['facebook', 'https://web.facebook.com/tokokue', 'https://www.facebook.com/tokokue'],
            ['x', 'https://twitter.com/tokokue', 'https://x.com/tokokue'],
            ['threads', '@tokokue', 'https://www.threads.net/@tokokue'],
            ['whatsapp', '+62 812-3456 7890', 'https://wa.me/6281234567890'],
            ['telegram', 't.me/tokokue', 'https://t.me/tokokue'],
            ['linkedin', 'https://www.linkedin.com/company/hellom/', 'https://www.linkedin.com/company/hellom'],
            ['pinterest', 'tokokue', 'https://www.pinterest.com/tokokue'],
            ['shopee', 'https://shopee.co.id/tokokue', 'https://shopee.co.id/tokokue'],
            ['tokopedia', 'toko-kue', 'https://www.tokopedia.com/toko-kue'],
            ['spotify', 'https://open.spotify.com/intl-id/artist/0TnOYISbd1XYRBk9myaseg', 'https://open.spotify.com/artist/0TnOYISbd1XYRBk9myaseg'],
            ['discord', 'https://discord.com/invite/abcDEF', 'https://discord.gg/abcDEF'],
            ['snapchat', 'tokokue', 'https://www.snapchat.com/add/tokokue'],
            ['twitch', 'tokokue', 'https://www.twitch.tv/tokokue'],
            ['email', 'Halo@Toko.ID', 'mailto:halo@toko.id'],
            ['website', 'tokokue.id', 'https://tokokue.id'],
        ];
        foreach ($cases as [$platform, $value, $url]) {
            $this->assertSame($url, SocialLinks::url($platform, $value), "{$platform}: {$value}");
        }
        $this->assertCount(18, SocialLinks::PLATFORMS);
        $this->assertSame(array_keys(SocialLinks::PLATFORMS), array_keys(SocialLinks::ICONS));

        foreach ([['instagram', 'javascript:alert(1)'], ['instagram', 'toko kue'], ['x', 'https://evil.com/x'], ['website', 'javascript:alert(1)'],
            ['email', 'bukan-email'], ['website', 'https://bank.example@evil.example'], ['website', 'halo@toko.id'], ['spotify', 'https://evil.com/artist/0TnOYISbd1XYRBk9myaseg'], ['whatsapp', '123'], ['unknown', 'x']] as [$platform, $value]) {
            $this->assertNull(SocialLinks::url($platform, $value), "{$platform}: {$value}");
        }
    }

    public function test_panel_is_cleaned_and_drawn_next_to_the_profile(): void
    {
        $doc = BlockSchema::normalize(['social' => ['items' => [
            ['platform' => 'instagram', 'value' => '@toko.kue', 'url' => 'javascript:alert(1)'], // client url ignored
            ['platform' => 'instagram', 'value' => '@kedua'],                                    // one per platform
            ['platform' => 'x', 'value' => 'bukan valid!'],                                     // invalid dropped
            ['platform' => 'whatsapp', 'value' => '0812 3456 7890'],
        ], 'position' => 'top', 'size' => 'lg', 'color' => 'custom', 'customColor' => '#AA3300']]);
        $this->assertSame([
            ['platform' => 'instagram', 'value' => '@toko.kue', 'url' => 'https://www.instagram.com/toko.kue'],
            ['platform' => 'whatsapp', 'value' => '0812 3456 7890', 'url' => 'https://wa.me/6281234567890'],
        ], $doc['social']['items']);
        $this->assertSame(['top', 'lg', 'custom', '#aa3300'], [$doc['social']['position'], $doc['social']['size'], $doc['social']['color'], $doc['social']['customColor']]);
        // Old documents without a panel get an empty one.
        $this->assertSame([], BlockSchema::normalize(['blocks' => []])['social']['items']);

        $seller = $this->seller();
        $seller['org']->forceFill(['landing_username' => 'sosmed' . $seller['org']->id])->save();
        $documents = app(LandingDocumentService::class);
        $publish = function (array $social) use ($documents, $seller) {
            $documents->saveDraft($seller['page']->fresh(), ['social' => $social, 'blocks' => [
                ['id' => 'p1', 'type' => 'profile', 'content' => ['name' => 'Toko Kue Bu Ani']],
                ['id' => 't1', 'type' => 'text', 'content' => ['body' => 'Pesan H-1 ya']],
            ]], null);
            $documents->publish($seller['page']->fresh(), $seller['user']);
            app(\App\Services\Landing\LandingShop::class)->bumpCache((int) $seller['org']->id);

            return $this->get('/sosmed' . $seller['org']->id)->assertOk()->getContent();
        };

        $html = $publish(['items' => [['platform' => 'instagram', 'value' => 'toko.kue'], ['platform' => 'email', 'value' => 'halo@toko.id']], 'position' => 'bottom', 'color' => 'brand']);
        $row = strpos($html, 'class="social-row');
        $this->assertGreaterThan(strpos($html, '<h1>Toko Kue Bu Ani</h1>'), $row);   // below the profile
        $this->assertLessThan(strpos($html, 'Pesan H-1 ya'), $row);         // before the next block
        $this->assertMatchesRegularExpression('~<a href="https://www\.instagram\.com/toko\.kue"\s+target="_blank" rel="noopener me"\s+aria-label="Instagram"~', $html);
        $this->assertStringContainsString('data-track="click" data-item="social:instagram" data-label="Instagram"', $html);
        $this->assertStringContainsString('--soc:#e1306c', $html);
        $this->assertMatchesRegularExpression('~<a href="mailto:halo@toko\.id"\s+aria-label="Email"~', $html); // same tab for email

        $html = $publish(['items' => [['platform' => 'tiktok', 'value' => '@tokokue']], 'position' => 'top']);
        $this->assertLessThan(strpos($html, '<h1>Toko Kue Bu Ani</h1>'), strpos($html, 'class="social-row'));
    }
}
