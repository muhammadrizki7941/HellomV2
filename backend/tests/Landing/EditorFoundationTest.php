<?php

namespace Tests\Landing;

use App\Http\Middleware\Api\EnsureAppEntitlement;
use App\Models\ApiToken;
use App\Models\LandingPageVersion;
use App\Models\User;
use App\Services\Landing\LandingDocumentService;
use App\Support\Landing\BlockSchema;
use App\Support\Landing\DocumentMigrator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

/** Link-in-bio editor, Fase 1: versioned documents (schema_version) and the editor preference. */
class EditorFoundationTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/v1/hellom/apps/landing-builder';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAppEntitlement::class);
    }

    private function v1Document(): array
    {
        return [
            'version' => 1,
            'theme' => ['preset' => 'ocean', 'font' => 'serif', 'buttonShape' => 'pill'],
            'settings' => ['whatsappNumber' => '08123456789'],
            'blocks' => [
                ['id' => 'p1', 'type' => 'profile', 'content' => ['name' => 'Toko Lama', 'bio' => 'Sejak 2019']],
                // An older editor kept styles inside content.
                ['id' => 'b1', 'type' => 'button', 'content' => ['text' => 'Pesan sekarang', 'linkUrl' => 'https://example.com', 'styles' => ['backgroundColor' => '#123456']]],
            ],
        ];
    }

    public function test_v1_documents_are_upgraded_without_losing_content(): void
    {
        $doc = BlockSchema::normalize($this->v1Document());

        $this->assertSame(DocumentMigrator::CURRENT, $doc['schema_version']);
        $this->assertArrayNotHasKey('version', $doc);
        $this->assertSame(['preset' => 'ocean', 'font' => 'serif', 'buttonShape' => 'pill'], $doc['theme']);
        $this->assertSame('Toko Lama', $doc['blocks'][0]['content']['name']);
        $this->assertSame('Pesan sekarang', $doc['blocks'][1]['content']['text']);
        $this->assertSame(['backgroundColor' => '#123456'], $doc['blocks'][1]['styles']);
        $this->assertArrayNotHasKey('styles', $doc['blocks'][1]['content']);
        // Upgrading twice changes nothing; a document from a newer app still loads.
        $this->assertSame($doc, BlockSchema::normalize($doc));
        $this->assertSame('Toko Lama', BlockSchema::normalize(['schema_version' => 99] + $this->v1Document())['blocks'][0]['content']['name']);
        $this->assertSame(1, DocumentMigrator::version(['blocks' => []]));
    }

    public function test_pages_published_before_versioning_still_render_and_reach_the_editor_upgraded(): void
    {
        $seller = $this->seller();
        $page = $seller['page'];
        $seller['org']->forceFill(['landing_username' => 'lama' . $seller['org']->id])->save();
        // A v1 snapshot and a v1 draft exactly as the old editor stored them.
        $version = LandingPageVersion::query()->create([
            'organization_id' => $seller['org']->id, 'landing_page_id' => $page->id, 'version_no' => 1, 'source_status' => 'published',
            'title' => $page->title, 'slug' => $page->slug, 'document' => $this->v1Document(), 'published_at' => now(),
        ]);
        $page->forceFill(['status' => 'published', 'is_home' => true, 'published_version_id' => $version->id, 'draft_document' => $this->v1Document()])->save();

        $this->get('/lama' . $seller['org']->id)->assertOk()->assertSee('Toko Lama')->assertSee('Pesan sekarang');

        $draft = app(LandingDocumentService::class)->draft($page->fresh());
        $this->assertSame(DocumentMigrator::CURRENT, $draft['document']['schema_version']);
        $this->getJson(self::BASE . "/site/pages/{$page->id}/document", ['Authorization' => 'Bearer ' . $seller['token']])
            ->assertOk()->assertJsonPath('data.document.schema_version', DocumentMigrator::CURRENT)
            ->assertJsonPath('data.document.blocks.1.styles.backgroundColor', '#123456');
    }

    public function test_editor_preference_is_saved_per_user(): void
    {
        $seller = $this->seller();
        $auth = ['Authorization' => 'Bearer ' . $seller['token']];

        $this->getJson(self::BASE . '/editor-preference', $auth)->assertOk()
            ->assertJsonPath('data.preference', null)->assertJsonPath('data.tour_done', false);
        $this->putJson(self::BASE . '/editor-preference', ['preference' => 'linktree'], $auth)->assertOk()
            ->assertJsonPath('data.preference', 'linktree');
        $this->putJson(self::BASE . '/editor-preference', ['tour_done' => true], $auth)->assertOk()
            ->assertJsonPath('data.preference', 'linktree')->assertJsonPath('data.tour_done', true);
        $this->putJson(self::BASE . '/editor-preference', ['preference' => 'wordpress'], $auth)->assertStatus(422)
            ->assertJsonPath('errors.preference.0', 'Pilihan belum dikenal.');

        // A second admin of the same shop has an own preference.
        $other = User::query()->create(['name' => 'Admin Dua', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('x'), 'role' => 'member', 'current_organization_id' => $seller['org']->id]);
        $seller['org']->users()->attach($other->id, ['role' => 'admin']);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $other->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);
        $this->getJson(self::BASE . '/editor-preference', ['Authorization' => 'Bearer ' . $plain])->assertOk()->assertJsonPath('data.preference', null);

        // POS staff of the shop cannot use the editor.
        $cashier = User::query()->create(['name' => 'Kasir', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'current_organization_id' => $seller['org']->id]);
        $seller['org']->users()->attach($cashier->id, ['role' => 'cashier']);
        $token = Str::random(40);
        ApiToken::query()->create(['user_id' => $cashier->id, 'name' => 't', 'token_hash' => hash('sha256', $token)]);
        $this->putJson(self::BASE . '/editor-preference', ['preference' => 'lynk'], ['Authorization' => 'Bearer ' . $token])->assertForbidden();
    }
    public function test_editor_preview_renders_the_unsaved_document_with_the_public_views(): void
    {
        $seller = $this->seller();
        $page = $seller['page'];
        $before = app(LandingDocumentService::class)->draft($page);
        $auth = ['Authorization' => 'Bearer ' . $seller['token']];
        $document = ['blocks' => [
            ['id' => 'p1', 'type' => 'profile', 'content' => ['name' => 'Toko <b>Belum Disimpan</b>', 'bio' => 'Bio baru']],
            ['id' => 'h1', 'type' => 'text', 'hidden' => true, 'content' => ['body' => 'Tersembunyi']],
            ['id' => 'b1', 'type' => 'button', 'content' => ['text' => 'Pesan via WA', 'actionType' => 'link', 'linkUrl' => 'javascript:alert(1)']],
        ]];

        $html = $this->postJson(self::BASE . "/site/pages/{$page->id}/render", ['document' => $document], $auth)->assertOk()->json('data.html');

        $this->assertStringContainsString('data-hl-block="p1"', $html);
        $this->assertStringContainsString('data-hl-block="b1"', $html);
        $this->assertStringContainsString('Toko &lt;b&gt;Belum Disimpan&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('Tersembunyi', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('Pratinjau draft', $html);
        $this->assertStringContainsString("send({ hl: 'ready' })", $html);
        // Nothing saved; the public page has no editor markers.
        $this->assertSame($before['revision'], app(LandingDocumentService::class)->draft($page->fresh())['revision']);

        // Another shop cannot render this page.
        $other = $this->seller();
        $this->postJson(self::BASE . "/site/pages/{$page->id}/render", ['document' => $document], ['Authorization' => 'Bearer ' . $other['token']])->assertNotFound();
    }

    public function test_page_quota_still_applies(): void
    {
        $seller = $this->seller();
        $auth = ['Authorization' => 'Bearer ' . $seller['token']];
        // The fixture shop already has its one free page.
        $this->postJson(self::BASE . '/site/pages', ['title' => 'Halaman Dua'], $auth)->assertStatus(422)->assertJsonPath('error.code', 'PAGE_QUOTA');
    }
}
