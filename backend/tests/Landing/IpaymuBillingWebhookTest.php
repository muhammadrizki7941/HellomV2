<?php

namespace Tests\Landing;

use App\Models\AppCatalog;
use App\Models\CheckoutIntent;
use App\Models\DigitalProduct;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\OrganizationWallet;
use App\Models\Plan;
use App\Models\ProductPurchase;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\IpaymuPaymentVerifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P0-3: subscription, digital product and wallet top-up notifications from iPaymu are
 * only booked after iPaymu's transaction API confirms status, reference and amount.
 */
class IpaymuBillingWebhookTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    /** @return array{org: Organization, user: User, intent: CheckoutIntent} */
    private function pendingCheckout(int $amount = 990000): array
    {
        $org = Organization::query()->create(['name' => 'Org ' . Str::random(4), 'slug' => 'org-' . Str::lower(Str::random(8)), 'status' => 'active']);
        $user = User::query()->create(['name' => 'Owner', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('x'), 'role' => 'admin', 'current_organization_id' => $org->id]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'landing_builder'], ['name' => 'Hellom Page', 'is_active' => true]);
        $plan = Plan::query()->create(['slug' => 'yr-' . Str::lower(Str::random(6)), 'name' => 'Tahunan', 'type' => 'subscription', 'price' => 99000, 'is_active' => true]);
        $subscription = Subscription::query()->create(['organization_id' => $org->id, 'app_id' => $app->id, 'plan_id' => $plan->id, 'status' => 'pending_payment', 'amount' => $amount, 'currency' => 'IDR', 'billing_cycle' => 'yearly']);
        $intent = CheckoutIntent::query()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'app_id' => $app->id, 'plan_id' => $plan->id, 'subscription_id' => $subscription->id,
            'intent_token' => 'ci_' . Str::random(20), 'status' => 'pending_payment', 'amount' => $amount, 'currency' => 'IDR']);

        return ['org' => $org, 'user' => $user, 'intent' => $intent];
    }

    private function notify(array $params, array $body, ?string $signatureOverride = null)
    {
        $query = $params + ['sig' => $signatureOverride ?? IpaymuPaymentVerifier::sign($params), 'token' => self::IPAYMU_TOKEN];

        return $this->post('/api/v1/hellom/webhooks/ipaymu?' . http_build_query($query), $body, ['Accept' => 'application/json']);
    }

    private function subscriptionParams(CheckoutIntent $intent): array
    {
        return ['purpose' => 'subscription_checkout', 'organization_id' => $intent->organization_id, 'subscription_id' => $intent->subscription_id,
            'checkout_intent_id' => $intent->id, 'reference_id' => $intent->intent_token];
    }

    public function test_subscription_is_activated_only_when_ipaymu_confirms_status_and_amount(): void
    {
        ['intent' => $intent] = $this->pendingCheckout(990000);

        // Body claims success, iPaymu says pending: nothing happens.
        $this->fakeIpaymuTransaction('trx-sub-1', $intent->intent_token, 990000, 0);
        $this->notify($this->subscriptionParams($intent), ['trx_id' => 'trx-sub-1', 'status' => 'berhasil'])->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame('pending_payment', $intent->fresh()->status);

        // Paid, but only the monthly price: refused.
        $this->fakeIpaymuTransaction('trx-sub-1', $intent->intent_token, 99000);
        $this->notify($this->subscriptionParams($intent), ['trx_id' => 'trx-sub-1', 'status' => 'berhasil'])->assertOk()->assertJsonPath('data.status', 'amount_mismatch');
        $this->assertSame('pending_payment', $intent->fresh()->status);

        // Paid in full: activated once, revenue booked once.
        $this->fakeIpaymuTransaction('trx-sub-1', $intent->intent_token, 990000);
        $this->notify($this->subscriptionParams($intent), ['trx_id' => 'trx-sub-1'])->assertOk()->assertJsonPath('data.status', 'processed');
        $this->notify($this->subscriptionParams($intent), ['trx_id' => 'trx-sub-1'])->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->assertSame('confirmed', $intent->fresh()->status);
        $this->assertSame('active', Entitlement::query()->where('organization_id', $intent->organization_id)->where('app_id', $intent->app_id)->value('status'));
        $this->assertSame(1, DB::table('platform_finance_ledgers')->where('reference_type', 'checkout_intents')->where('reference_id', $intent->id)->count());
    }

    public function test_tampered_or_reused_notifications_are_refused(): void
    {
        ['intent' => $intent] = $this->pendingCheckout(990000);
        ['intent' => $other] = $this->pendingCheckout(990000);

        // Notify URL pointed at another organization: signature no longer matches.
        $params = $this->subscriptionParams($intent);
        $tampered = ['organization_id' => $other->organization_id] + $params;
        $this->post('/api/v1/hellom/webhooks/ipaymu?' . http_build_query($tampered + ['sig' => IpaymuPaymentVerifier::sign($params), 'token' => self::IPAYMU_TOKEN]), ['trx_id' => 'x'], ['Accept' => 'application/json'])
            ->assertStatus(401);

        // One payment (reference of the first checkout) cannot activate the second one.
        $this->fakeIpaymuTransaction('trx-shared', $intent->intent_token, 990000);
        $this->notify($this->subscriptionParams($other), ['trx_id' => 'trx-shared'])->assertOk()->assertJsonPath('data.status', 'reference_mismatch');
        $this->assertSame('pending_payment', $other->fresh()->status);
    }

    public function test_reconcile_does_not_trust_a_transaction_id_from_the_browser(): void
    {
        ['user' => $user, 'intent' => $intent] = $this->pendingCheckout(990000);
        $token = Str::random(40);
        \App\Models\ApiToken::query()->create(['user_id' => $user->id, 'name' => 't', 'token_hash' => hash('sha256', $token)]);

        // A paid transaction of someone else's (cheaper) payment.
        $this->fakeIpaymuTransaction('trx-cheap', 'topup_SOMEONEELSE', 10000);
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/hellom/billing/checkout-reconcile', ['intent_token' => $intent->intent_token, 'transaction_id' => 'trx-cheap'])
            ->assertOk()->assertJsonPath('data.active', false);
        $this->assertSame('pending_payment', $intent->fresh()->status);
    }

    public function test_wallet_topup_credits_the_amount_ipaymu_reports_and_only_for_signed_urls(): void
    {
        ['org' => $org, 'user' => $user] = $this->pendingCheckout();
        $reference = 'topup_' . Str::upper(Str::random(18));
        $params = ['purpose' => 'wallet_topup', 'organization_id' => $org->id, 'user_id' => $user->id, 'reference_id' => $reference];
        $this->fakeIpaymuTransaction('trx-top-1', $reference, 50000);

        // Old style URL without signature: not credited.
        $this->post('/api/v1/hellom/webhooks/ipaymu?' . http_build_query($params + ['token' => self::IPAYMU_TOKEN]), ['trx_id' => 'trx-top-1', 'amount' => 50000], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status', 'unsigned');
        $this->assertNull(OrganizationWallet::query()->where('organization_id', $org->id)->value('available_balance'));

        // Body claims a huge amount; iPaymu's own amount is credited, once.
        $this->notify($params, ['trx_id' => 'trx-top-1', 'status' => 'berhasil', 'amount' => 9999999])->assertOk()->assertJsonPath('data.status', 'processed');
        $this->notify($params, ['trx_id' => 'trx-top-1', 'status' => 'berhasil'])->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->assertSame(50000, (int) OrganizationWallet::query()->where('organization_id', $org->id)->value('available_balance'));
    }

    public function test_paid_digital_product_is_never_downgraded_by_a_late_notification(): void
    {
        ['user' => $user] = $this->pendingCheckout();
        $product = DigitalProduct::query()->create(['slug' => 'ebook-' . Str::lower(Str::random(6)), 'name' => 'Ebook', 'category' => 'ebook', 'type' => 'paid', 'price' => 75000, 'currency' => 'IDR', 'is_published' => true]);
        $purchase = ProductPurchase::query()->create(['user_id' => $user->id, 'product_id' => $product->id, 'transaction_code' => 'PUR-' . Str::upper(Str::random(10)), 'amount_paid' => 75000,
            'payment_method' => 'gateway', 'payment_status' => 'pending', 'payment_gateway' => 'ipaymu']);
        $params = ['purpose' => 'product_purchase', 'purchase_id' => $purchase->id, 'product_id' => $product->id, 'user_id' => $user->id, 'reference_id' => $purchase->transaction_code];

        $this->fakeIpaymuTransaction('trx-pp-1', $purchase->transaction_code, 75000);
        $this->notify($params, ['trx_id' => 'trx-pp-1'])->assertOk()->assertJsonPath('data.status', 'processed');
        $this->assertSame('paid', $purchase->fresh()->payment_status);

        // A late "failed" notification (iPaymu now reports expired for that id) changes nothing.
        $this->fakeIpaymuTransaction('trx-pp-1', $purchase->transaction_code, 75000, -2);
        $this->notify($params, ['trx_id' => 'trx-pp-1', 'status' => 'gagal'])->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->assertSame('paid', $purchase->fresh()->payment_status);
    }
}
