<?php

namespace Tests\Landing;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\EntitlementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/** Owner decision after Fase 3: subscriptions paid with mock money are revoked. */
class RevokeSubscriptionTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    private ?int $planId = null;

    private function subscription(int $orgId, int $appId, ?string $endsAt): Subscription
    {
        $this->planId ??= (int) Plan::query()->forceCreate(['slug' => 'test-plan-' . uniqid(), 'name' => 'Test', 'type' => 'subscription', 'price' => 15000, 'is_active' => true])->id;

        return Subscription::query()->forceCreate([
            'organization_id' => $orgId, 'app_id' => $appId, 'plan_id' => $this->planId, 'status' => 'active', 'amount' => 15000,
            'currency' => 'IDR', 'billing_cycle' => 'monthly', 'starts_at' => now()->subMonth(), 'ends_at' => $endsAt,
        ]);
    }

    public function test_revoke_ends_access_unless_another_subscription_covers_it(): void
    {
        $seller = $this->seller();
        $appId = (int) AppCatalog::query()->where('slug', 'pos')->value('id');
        $mockPaid = $this->subscription($seller['org']->id, $appId, null);
        $entitlement = Entitlement::query()->create(['organization_id' => $seller['org']->id, 'app_id' => $appId, 'status' => 'active', 'starts_at' => now()->subMonth(), 'ends_at' => null]);

        $this->artisan('billing:revoke-subscription', ['ids' => [$mockPaid->id]])->assertSuccessful();
        $this->assertSame('active', $mockPaid->fresh()->status, 'report mode writes nothing');

        $this->artisan('billing:revoke-subscription', ['ids' => [$mockPaid->id], '--force' => true])->assertSuccessful();
        $this->assertSame('cancelled', $mockPaid->fresh()->status);
        $this->assertFalse($entitlement->fresh()->allowsAccess());

        // A real subscription of the same app keeps the organization covered.
        $real = $this->subscription($seller['org']->id, $appId, now()->addMonth()->toDateTimeString());
        $entitlement->fresh()->forceFill(['status' => 'active', 'ends_at' => null])->save();
        $second = $this->subscription($seller['org']->id, $appId, null);
        app(EntitlementService::class)->revoke($second, now(), 'test');
        $this->assertTrue($entitlement->fresh()->allowsAccess());
        $this->assertSame($real->ends_at->toDateTimeString(), $entitlement->fresh()->ends_at->toDateTimeString());
    }
}
