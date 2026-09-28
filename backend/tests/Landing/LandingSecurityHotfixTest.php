<?php

namespace Tests\Landing;

use App\Models\ApiToken;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\LandingBlock;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Pos\PosTestCase;

/** Audit hotfixes LB-18 (paid links hidden), LB-19 (HTML sanitised), LB-20 (no SVG upload). */
class LandingSecurityHotfixTest extends PosTestCase
{
    use DatabaseTransactions;

    /** @return array{org: Organization, page: OrganizationLandingPage, token: string} */
    private function seller(): array
    {
        $org = Organization::query()->create(['name' => 'Toko ' . Str::random(4), 'slug' => 'toko-' . Str::lower(Str::random(8)), 'status' => 'active']);
        $user = User::query()->create(['name' => 'Penjual', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'member', 'current_organization_id' => $org->id]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'landing_builder'], ['name' => 'Landing Page Builder', 'is_active' => true]);
        Entitlement::query()->create(['organization_id' => $org->id, 'app_id' => $app->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);
        $page = OrganizationLandingPage::query()->create(['organization_id' => $org->id, 'title' => 'Toko', 'slug' => 'toko', 'status' => 'published', 'published_at' => now()]);

        return ['org' => $org, 'page' => $page, 'token' => $plain];
    }

    public function test_paid_delivery_links_are_not_public_but_free_downloads_are(): void
    {
        ['org' => $org, 'page' => $page] = $this->seller();
        LandingBlock::query()->create(['organization_id' => $org->id, 'landing_page_id' => $page->id, 'block_key' => 'pdf-paid', 'block_type' => 'pdf', 'sort_order' => 1, 'is_visible' => true,
            'content' => ['title' => 'Berbayar', 'accessType' => 'paid', 'price' => 'Rp 25.000', 'fileUrl' => 'https://drive.google.com/file/d/rahasia/view']]);
        LandingBlock::query()->create(['organization_id' => $org->id, 'landing_page_id' => $page->id, 'block_key' => 'pdf-free', 'block_type' => 'pdf', 'sort_order' => 2, 'is_visible' => true,
            'content' => ['title' => 'Gratis', 'accessType' => 'free', 'fileUrl' => 'https://example.test/gratis.pdf']]);
        LandingBlock::query()->create(['organization_id' => $org->id, 'landing_page_id' => $page->id, 'block_key' => 'product', 'block_type' => 'product', 'sort_order' => 3, 'is_visible' => true,
            'content' => ['name' => 'E-book', 'price' => 'Rp 49.000', 'fileUrl' => 'https://drive.google.com/drive/folders/rahasia', 'productUrl' => 'https://example.test/info']]);

        $response = $this->getJson("/api/v1/hellom/public/landing/{$org->slug}/toko")->assertOk();
        $body = $response->getContent();
        $this->assertStringNotContainsString('rahasia', $body);

        $blocks = collect($response->json('data.blocks'))->keyBy('block_key');
        $this->assertArrayNotHasKey('fileUrl', $blocks['pdf-paid']['content']);
        $this->assertSame('https://example.test/gratis.pdf', $blocks['pdf-free']['content']['fileUrl']);
        $this->assertSame('https://example.test/info', $blocks['product']['content']['productUrl']);
    }

    public function test_custom_html_is_sanitised_on_save_and_on_output(): void
    {
        ['org' => $org, 'page' => $page, 'token' => $token] = $this->seller();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/hellom/apps/landing-builder/pages/{$page->id}/blocks", [
                'block_key' => 'html-1', 'block_type' => 'html',
                'content' => ['html' => '<p onclick="steal()">Halo <strong>kak</strong></p><script>fetch("//evil")</script><a href="javascript:alert(1)">x</a><img src=x onerror=alert(1)>'],
            ])->assertCreated();

        $stored = LandingBlock::query()->where('block_key', 'html-1')->value('content');
        $html = is_array($stored) ? $stored['html'] : json_decode((string) $stored, true)['html'];
        $this->assertStringContainsString('<strong>kak</strong>', $html);
        foreach (['<script', 'onclick', 'onerror', 'javascript:'] as $bad) {
            $this->assertStringNotContainsString($bad, $html);
        }

        // A row stored before sanitising existed is cleaned on the way out.
        DB::table('landing_blocks')->insert([
            'organization_id' => $org->id, 'landing_page_id' => $page->id, 'block_key' => 'legacy', 'block_type' => 'html', 'sort_order' => 9, 'is_visible' => 1,
            'content' => json_encode(['html' => '<img src=x onerror="alert(document.cookie)"><b>lama</b>']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $public = $this->getJson("/api/v1/hellom/public/landing/{$org->slug}/toko")->assertOk()->getContent();
        $this->assertStringNotContainsString('onerror', $public);
        $this->assertStringContainsString('lama', $public);
    }

    public function test_svg_upload_is_rejected(): void
    {
        Storage::fake('public');
        ['token' => $token] = $this->seller();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/hellom/apps/landing-builder/assets/upload', ['file' => $svg], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/hellom/apps/landing-builder/assets/upload', ['file' => UploadedFile::fake()->image('ok.png', 10, 10)], ['Accept' => 'application/json'])
            ->assertCreated();
    }
}
