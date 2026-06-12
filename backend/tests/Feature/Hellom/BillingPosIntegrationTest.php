<?php

namespace Tests\Feature\Hellom;

use App\Models\Entitlement;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingPosIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_confirm_mock_provisions_pos_for_organization(): void
    {
        $session = $this->registerMember();
        $token = (string) $session['token'];
        $organizationId = (int) $session['organization_id'];

        $this->activatePosViaMockCheckout($token);

        $organization = Organization::query()->findOrFail($organizationId);

        $this->assertNotNull($organization->pos_tenant_slug);
        $this->assertNotNull($organization->pos_tenant_name);
        $this->assertNotNull($organization->pos_provisioned_at);

        $this->assertDatabaseHas('product_purchase_settings', [
            'organization_id' => $organizationId,
            'service_type' => 'dine_in',
        ]);
    }

    public function test_purchase_settings_requires_pos_entitlement_until_checkout_confirmed(): void
    {
        $session = $this->registerMember();
        $token = (string) $session['token'];

        $locked = $this->getJson('/api/v1/hellom/purchase-settings', $this->authHeaders($token));
        $locked->assertStatus(403)
            ->assertJsonPath('error.code', 'APP_LOCKED');

        $this->activatePosViaMockCheckout($token);

        $unlocked = $this->getJson('/api/v1/hellom/purchase-settings', $this->authHeaders($token));
        $unlocked->assertOk()
            ->assertJsonPath('data.0.service_type', 'dine_in');
    }

    public function test_expired_pos_entitlement_blocks_probe(): void
    {
        $session = $this->registerMember();
        $token = (string) $session['token'];
        $organizationId = (int) $session['organization_id'];

        $this->activatePosViaMockCheckout($token);

        $entitlement = Entitlement::query()
            ->where('organization_id', $organizationId)
            ->whereHas('app', fn ($query) => $query->where('slug', 'pos'))
            ->firstOrFail();

        $entitlement->forceFill([
            'ends_at' => now()->subMinute(),
        ])->save();

        $response = $this->getJson('/api/v1/hellom/apps/pos/probe', $this->authHeaders($token));

        $response->assertStatus(403)
            ->assertJsonPath('data.status', 'expired');
    }

    public function test_pos_access_endpoint_returns_launch_urls_after_unlock(): void
    {
        $session = $this->registerMember();
        $token = (string) $session['token'];

        $this->activatePosViaMockCheckout($token);

        $response = $this->getJson('/api/v1/hellom/apps/pos/access', $this->authHeaders($token));

        $response->assertOk()
            ->assertJsonPath('data.app', 'pos');

        $adminUrl = (string) $response->json('data.access.admin_url');
        $cashierUrl = (string) $response->json('data.access.cashier_url');
        $customerUrl = (string) $response->json('data.access.customer_url');

        $this->assertStringContainsString('/admin', $adminUrl);
        $this->assertStringContainsString('/cashier/login', $cashierUrl);
        $this->assertStringContainsString('/pos', $customerUrl);
        $this->assertNotSame('', (string) $response->json('data.organization.pos_tenant_slug'));
    }

    /**
     * @return array{token:string,organization_id:int}
     */
    private function registerMember(): array
    {
        $suffix = (string) now()->timestamp . '_' . (string) random_int(1000, 9999);

        $response = $this->postJson('/api/v1/hellom/auth/register', [
            'name' => 'POS Member',
            'email' => "pos.member.{$suffix}@example.com",
            'password' => 'secret1234',
            'organization_name' => "POS Org {$suffix}",
        ]);

        $response->assertStatus(201);

        return [
            'token' => (string) $response->json('data.token'),
            'organization_id' => (int) $response->json('data.user.current_organization.id'),
        ];
    }

    private function activatePosViaMockCheckout(string $token): void
    {
        $intentResponse = $this->postJson('/api/v1/hellom/billing/checkout-intent-mock', [
            'app_slug' => 'pos',
            'plan_slug' => 'pos_starter',
        ], $this->authHeaders($token));

        $intentResponse->assertOk();

        $intentToken = (string) $intentResponse->json('data.checkout_intent.intent_token');

        $confirmResponse = $this->postJson('/api/v1/hellom/billing/checkout-confirm-mock', [
            'intent_token' => $intentToken,
        ], $this->authHeaders($token));

        $confirmResponse->assertOk()
            ->assertJsonPath('data.app_slug', 'pos')
            ->assertJsonPath('data.intent_status', 'confirmed');
    }

    /**
     * @return array{Authorization:string,Accept:string}
     */
    private function authHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];
    }
}
