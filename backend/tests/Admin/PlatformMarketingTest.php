<?php

namespace Tests\Admin;

use App\Models\PlatformMarketingEvent;
use App\Models\SystemSetting;
use App\Services\PlatformMarketing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PlatformMarketingTest extends AdminTestCase
{
    private function context(): array
    {
        return ['consent' => 'granted', 'visitor_id' => 'visitor-12345678', 'session_id' => 'session-12345678', 'source' => 'facebook', 'campaign' => 'pos-launch', 'path' => '/aplikasi/pos'];
    }

    public function test_registration_is_recorded_only_after_success(): void
    {
        $response = $this->postJson('/api/v1/hellom/auth/register', [
            'name' => 'Marketing Test', 'email' => Str::random(12) . '@example.test', 'password' => 'test-password-123',
            'organization_name' => 'Test analytics', 'marketing' => $this->context(),
        ])->assertCreated();
        $this->assertDatabaseHas('platform_marketing_events', ['event_name' => 'CompleteRegistration', 'campaign' => 'pos-launch']);
    }

    public function test_digital_product_purchase_is_recorded_once_and_context_is_hidden(): void
    {
        request()->merge(['marketing' => $this->context()]);
        $product = \App\Models\DigitalProduct::create(['slug' => 'marketing-' . Str::random(10), 'name' => 'App', 'category' => 'template', 'type' => 'paid', 'price' => 48000, 'currency' => 'IDR', 'is_published' => true]);
        $purchase = \App\Models\ProductPurchase::create(['user_id' => $this->makeUser('member')->id, 'product_id' => $product->id, 'transaction_code' => 'MKT-' . Str::random(10), 'amount_paid' => 48000, 'payment_status' => 'pending', 'payment_gateway' => 'manual']);
        $this->assertArrayNotHasKey('marketing_context', $purchase->toArray());
        $this->assertDatabaseMissing('platform_marketing_events', ['event_id' => 'purchase_product_' . $purchase->id]);
        $purchase->update(['payment_status' => 'paid']);
        $purchase->save();
        $this->assertSame(1, PlatformMarketingEvent::where('event_id', 'purchase_product_' . $purchase->id)->count());
    }

    public function test_test_event_requires_saved_config_and_stays_out_of_production(): void
    {
        $admin = $this->superAdmin();
        $this->api($admin, 'POST', '/admin/marketing/test')->assertStatus(422);
        app(PlatformMarketing::class)->save(['enabled' => true, 'meta_enabled' => true, 'pixel_id' => '12345678901', 'api_version' => 'v25.0', 'token' => 'secret', 'test_event_code' => 'TEST123']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        $this->api($admin, 'POST', '/admin/marketing/test')->assertOk()->assertJsonPath('data.delivery_status', 'sent');
        Http::assertSent(fn ($r) => $r['test_event_code'] === 'TEST123');
    }

    public function test_public_events_require_consent_and_cannot_forge_purchases(): void
    {
        $body = ['event_name' => 'Purchase', 'event_id' => (string) Str::uuid(), 'marketing' => $this->context()];
        $this->postJson('/api/v1/hellom/public/marketing/events', $body)->assertStatus(422);
        $body['event_name'] = 'PageView';
        $body['marketing']['consent'] = 'denied';
        $this->postJson('/api/v1/hellom/public/marketing/events', $body)->assertOk()->assertJsonPath('data.recorded', false);
        $body['marketing'] = $this->context();
        $body['marketing']['path'] = '/reset-password?token=secret';
        $this->postJson('/api/v1/hellom/public/marketing/events', $body)->assertOk()->assertJsonPath('data.recorded', true);
        $this->postJson('/api/v1/hellom/public/marketing/events', $body)->assertOk();
        $this->assertSame(1, PlatformMarketingEvent::where('event_id', 'web_' . $body['event_id'])->count());
        $this->assertSame('/', PlatformMarketingEvent::where('event_id', 'web_' . $body['event_id'])->value('path'));
    }

    public function test_settings_are_admin_only_and_never_disclose_token(): void
    {
        $this->api($this->makeUser('member'), 'GET', '/admin/marketing')->assertForbidden();
        $admin = $this->superAdmin();
        $this->api($admin, 'PUT', '/admin/marketing/settings', ['enabled' => true, 'meta_enabled' => true, 'pixel_id' => '12345678901', 'api_version' => 'v25.0', 'token' => 'private-token', 'test_event_code' => 'TEST123'])
            ->assertOk()->assertJsonPath('data.token_configured', true)->assertJsonMissing(['token' => 'private-token']);
        $this->assertNotSame('private-token', SystemSetting::get('platform_marketing_token'));
        $this->getJson('/api/v1/hellom/public/marketing/config')->assertOk()->assertJsonMissing(['token' => 'private-token']);
        $this->api($admin, 'GET', '/admin/marketing/settings')->assertJsonMissing(['token' => 'private-token']);
    }

    public function test_meta_payload_uses_real_amount_and_id_and_retries_idempotently(): void
    {
        $service = app(PlatformMarketing::class);
        $service->save(['enabled' => true, 'meta_enabled' => true, 'pixel_id' => '12345678901', 'api_version' => 'v25.0', 'token' => 'secret', 'test_event_code' => 'TEST123']);
        $event = $service->record('Purchase', 'purchase_test_real', $this->context(), 48000, 'pos', $this->makeUser('member'));
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['error' => ['code' => 190]], 400)->push(['events_received' => 1])]);
        $service->deliver($event->id);
        $this->assertSame('failed', $event->fresh()->delivery_status);
        $service->deliver($event->id);
        $service->deliver($event->id);
        $this->assertSame('sent', $event->fresh()->delivery_status);
        $this->assertSame(2, $event->fresh()->attempts);
        Http::assertSent(fn ($r) => $r['data'][0]['event_id'] === 'purchase_test_real' && $r['data'][0]['custom_data']['value'] === 48000
            && $r['data'][0]['custom_data']['currency'] === 'IDR' && !isset($r['test_event_code']) && strlen($r['data'][0]['user_data']['em'][0]) === 64);
    }

    public function test_reports_exclude_test_events_and_group_attribution(): void
    {
        $service = app(PlatformMarketing::class);
        $service->record('PageView', 'report-page', $this->context());
        $service->record('Purchase', 'report-purchase', $this->context(), 48000, 'pos');
        $service->record('Purchase', 'report-test', $this->context(), 900000, 'pos', test: true);
        $this->api($this->superAdmin(), 'GET', '/admin/marketing?from=' . now()->toDateString() . '&to=' . now()->toDateString())
            ->assertOk()->assertJsonPath('data.revenue', 48000)->assertJsonPath('data.visitors', 1)
            ->assertJsonPath('data.campaigns.0.campaign', 'pos-launch');
    }
}
