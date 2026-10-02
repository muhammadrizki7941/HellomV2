<?php

namespace Tests\Admin;

use App\Models\AppCatalog;
use App\Models\CheckoutIntent;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** P1-1/P1-2: manual transfer approval is booked once; rejection only cancels unpaid subscriptions. */
class ManualCheckoutTest extends AdminTestCase
{
    private function manualCheckout(Organization $org, User $owner, string $subscriptionStatus = 'pending_payment'): CheckoutIntent
    {
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'landing_builder'], ['name' => 'Hellom Page', 'is_active' => true]);
        $plan = Plan::query()->create(['slug' => 'm-' . Str::lower(Str::random(6)), 'name' => 'Bulanan', 'type' => 'subscription', 'price' => 99000, 'is_active' => true]);
        $subscription = Subscription::query()->create(['organization_id' => $org->id, 'app_id' => $app->id, 'plan_id' => $plan->id, 'status' => $subscriptionStatus, 'amount' => 99000, 'currency' => 'IDR', 'billing_cycle' => 'monthly']);

        return CheckoutIntent::query()->create(['organization_id' => $org->id, 'user_id' => $owner->id, 'app_id' => $app->id, 'plan_id' => $plan->id, 'subscription_id' => $subscription->id,
            'intent_token' => 'ci_' . Str::random(20), 'status' => 'manual_review', 'amount' => 99000, 'currency' => 'IDR']);
    }

    public function test_approving_twice_books_revenue_and_invoice_once(): void
    {
        ['org' => $org] = $this->makeOrganization('MAN');
        $owner = $this->makeUser('admin', $org);
        $intent = $this->manualCheckout($org, $owner);
        $admin = $this->superAdmin();

        $this->api($admin, 'POST', "/admin/billing/manual-checkouts/{$intent->id}/approve")->assertOk()->assertJsonPath('data.status', 'confirmed');
        // Second click: idempotent answer, nothing booked again.
        $this->api($admin, 'POST', "/admin/billing/manual-checkouts/{$intent->id}/approve")->assertOk()->assertJsonPath('message', 'Checkout ini sudah disetujui sebelumnya');

        $this->assertSame('confirmed', $intent->fresh()->status);
        $this->assertSame('active', $intent->subscription->fresh()->status);
        $this->assertSame(1, DB::table('platform_finance_ledgers')->where('reference_type', 'checkout_intents')->where('reference_id', $intent->id)->count());
        $this->assertSame(1, DB::table('invoices')->where('subscription_id', $intent->subscription_id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'billing.manual_checkout_approved')->where('entity_id', $intent->id)->count());
    }

    public function test_reject_cancels_only_an_unpaid_subscription(): void
    {
        ['org' => $org] = $this->makeOrganization('REJ');
        $owner = $this->makeUser('admin', $org);
        $pending = $this->manualCheckout($org, $owner);
        $alreadyActive = $this->manualCheckout($org, $owner, 'active');
        $admin = $this->superAdmin();

        $this->api($admin, 'POST', "/admin/billing/manual-checkouts/{$pending->id}/reject")->assertOk();
        $this->assertSame('cancelled', $pending->subscription->fresh()->status);

        $this->api($admin, 'POST', "/admin/billing/manual-checkouts/{$alreadyActive->id}/reject")->assertOk();
        $this->assertSame('rejected', $alreadyActive->fresh()->status);
        $this->assertSame('active', $alreadyActive->subscription->fresh()->status);

        $this->api($admin, 'POST', "/admin/billing/manual-checkouts/{$pending->id}/reject")->assertStatus(422);
    }
}
