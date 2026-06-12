<?php

namespace Tests\Feature\Hellom;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingBuilderControllerTest extends TestCase
{
    use RefreshDatabase;
    use HellomTestHelpers;

    private function seedEntitledUser(): array
    {
        $org = Organization::query()->create([
            'name' => 'LB Org',
            'slug' => 'lb-org',
            'status' => 'active',
        ]);

        [$user, $token] = $this->createHellomUser();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        $app = AppCatalog::query()->firstOrCreate(
            ['slug' => 'landing_builder'],
            [
                'name' => 'Landing Builder',
                'is_active' => true,
            ]
        );

        $plan = Plan::query()->firstOrCreate(
            ['slug' => 'free'],
            [
                'app_id' => $app->id,
                'name' => 'Free',
                'type' => 'free',
                'price' => 0,
            ]
        );

        Entitlement::query()->create([
            'organization_id' => $org->id,
            'app_id' => $app->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        return [$org, $user, $token];
    }

    public function test_list_pages_empty(): void
    {
        [$org, $user, $token] = $this->seedEntitledUser();

        $response = $this->getJson('/api/v1/hellom/apps/landing-builder/pages', $this->hellomHeaders($token));

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_create_and_show_page(): void
    {
        [$org, $user, $token] = $this->seedEntitledUser();

        $create = $this->postJson('/api/v1/hellom/apps/landing-builder/pages', [
            'title' => 'My Landing',
            'slug' => 'my-landing',
        ], $this->hellomHeaders($token));

        $create->assertStatus(201)
            ->assertJsonPath('success', true);

        $pageId = $create->json('data.id');

        $show = $this->getJson("/api/v1/hellom/apps/landing-builder/pages/{$pageId}", $this->hellomHeaders($token));
        $show->assertOk()
            ->assertJsonPath('data.title', 'My Landing');
    }

    public function test_update_page(): void
    {
        [$org, $user, $token] = $this->seedEntitledUser();

        $create = $this->postJson('/api/v1/hellom/apps/landing-builder/pages', [
            'title' => 'Original',
            'slug' => 'original',
        ], $this->hellomHeaders($token));

        $pageId = $create->json('data.id');

        $update = $this->putJson("/api/v1/hellom/apps/landing-builder/pages/{$pageId}", [
            'title' => 'Updated',
        ], $this->hellomHeaders($token));

        $update->assertOk()
            ->assertJsonPath('data.title', 'Updated');
    }

    public function test_delete_page(): void
    {
        [$org, $user, $token] = $this->seedEntitledUser();

        $create = $this->postJson('/api/v1/hellom/apps/landing-builder/pages', [
            'title' => 'To Delete',
            'slug' => 'to-delete',
        ], $this->hellomHeaders($token));

        $pageId = $create->json('data.id');

        $delete = $this->deleteJson("/api/v1/hellom/apps/landing-builder/pages/{$pageId}", [], $this->hellomHeaders($token));
        $delete->assertOk();
    }

    public function test_landing_pages_blocked_without_entitlement(): void
    {
        $org = Organization::query()->create([
            'name' => 'No Ent Org',
            'slug' => 'no-ent',
            'status' => 'active',
        ]);

        [$user, $token] = $this->createHellomUser();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        $response = $this->getJson('/api/v1/hellom/apps/landing-builder/pages', $this->hellomHeaders($token));

        $response->assertStatus(403);
    }

    public function test_public_landingpage_route_returns_published_page_by_organization(): void
    {
        $org = Organization::query()->create([
            'name' => 'Public Org',
            'slug' => 'public-org',
            'status' => 'active',
        ]);

        OrganizationLandingPage::query()->create([
            'organization_id' => $org->id,
            'title' => 'Public Landing',
            'slug' => 'landing-page',
            'status' => 'published',
            'content' => [
                'theme' => 'industrial',
                'hero' => [
                    'title' => 'Public Landing',
                    'subtitle' => 'Live landing page',
                ],
            ],
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/hellom/public/landingpage/public-org');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.page.public_url', '/p/landingpage/public-org')
            ->assertJsonPath('data.seo.canonical_path', '/p/landingpage/public-org');
    }
}
