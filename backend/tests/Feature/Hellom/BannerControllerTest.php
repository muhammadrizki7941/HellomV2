<?php

namespace Tests\Feature\Hellom;

use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BannerControllerTest extends TestCase
{
    use RefreshDatabase;
    use HellomTestHelpers;

    public function test_super_admin_can_publish_dashboard_banner_for_users(): void
    {
        [, $token] = $this->createHellomUser(['role' => 'admin']);

        $this->postJson('/api/v1/hellom/admin/banners', [
            'title' => 'Promo Dashboard',
            'subtitle' => 'Penawaran terbaru',
            'position' => 'dashboard',
            'is_active' => true,
        ], $this->hellomHeaders($token))
            ->assertCreated()
            ->assertJsonPath('data.position', 'dashboard');

        Banner::query()->create([
            'title' => 'Banner Landing',
            'position' => 'hero',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/hellom/public/banners?position=dashboard')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.title', 'Promo Dashboard');
    }
}
