<?php

namespace Tests\Admin;

use App\Models\LandingArticle;

/** P1-8: article HTML is sanitised before it is stored (rendered on the dashboard origin). */
class ContentSecurityTest extends AdminTestCase
{
    public function test_article_content_is_sanitised_on_save(): void
    {
        $response = $this->api($this->superAdmin(), 'POST', '/admin/landing-content/articles', [
            'title' => 'Tips kasir',
            'content' => '<h2>Judul</h2><p onclick="steal()">Isi <a href="javascript:alert(1)">link</a></p><script>alert(1)</script><img src="x" onerror="alert(1)">',
            'is_active' => true,
        ])->assertCreated();

        $content = (string) LandingArticle::query()->findOrFail($response->json('data.id'))->content;
        $this->assertStringContainsString('<h2>Judul</h2>', $content);
        foreach (['<script', 'onclick', 'onerror', 'javascript:'] as $dangerous) {
            $this->assertStringNotContainsString($dangerous, $content);
        }
    }

    public function test_repeated_titles_get_unique_slugs_instead_of_a_server_error(): void
    {
        $admin = $this->superAdmin();
        $title = 'Promo Akhir Tahun ' . uniqid();

        $first = $this->api($admin, 'POST', '/admin/landing-content/articles', ['title' => $title])->assertCreated()->json('data.slug');
        $second = $this->api($admin, 'POST', '/admin/landing-content/articles', ['title' => $title])->assertCreated()->json('data.slug');

        $this->assertSame($first . '-2', $second);
    }
}
