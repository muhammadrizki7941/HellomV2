<?php

namespace Tests\Landing;

use App\Jobs\SendLandingSaleEmails;
use App\Models\LandingPageOrder;
use App\Models\PaymentWebhookLog;
use App\Models\SellerBalance;
use App\Models\SellerLedgerEntry;
use App\Services\SellerFinance\FeeCalculator;
use App\Services\SellerFinance\FinanceSettings;
use App\Services\SellerFinance\LandingPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** Fase 2: order payment is only accepted when the gateway confirms it, exactly once. */
class LandingPaymentTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    public function test_duplicate_webhook_credits_the_seller_once(): void
    {
        Queue::fake();
        $seller = $this->seller(100000);
        $order = $this->pendingOrder($seller);
        $this->fakeIpaymuTransaction('trx-1', (string) $order->reference_id, 100000, fee: 4000);

        $this->ipaymuWebhook($order, 'trx-1')->assertOk()->assertJsonPath('data.status', 'processed');
        $this->ipaymuWebhook($order, 'trx-1')->assertOk()->assertJsonPath('data.status', 'duplicate');

        $order->refresh();
        $this->assertSame(LandingPageOrder::STATUS_PAID, $order->status);
        // 5% of 100.000 = 5.000 ≥ gateway fee 4.000 → seller gets 95.000, Hellom keeps 1.000.
        $this->assertSame(5000, (int) $order->commission_amount);
        $this->assertSame(4000, (int) $order->gateway_fee_amount);
        $this->assertSame(95000, (int) $order->net_amount);
        $this->assertSame(95000, (int) SellerBalance::query()->find($seller['org']->id)->available);
        $this->assertSame(1, SellerLedgerEntry::query()->where('order_id', $order->id)->where('type', 'sale')->count());
        Queue::assertPushed(SendLandingSaleEmails::class, 1);
        $this->assertSame(2, PaymentWebhookLog::query()->where('reference', $order->reference_id)->count());
    }

    public function test_amount_mismatch_is_rejected(): void
    {
        $seller = $this->seller(100000);
        $order = $this->pendingOrder($seller);
        $this->fakeIpaymuTransaction('trx-2', (string) $order->reference_id, 1000); // paid Rp1.000 for a Rp100.000 order

        $this->ipaymuWebhook($order, 'trx-2')->assertOk()->assertJsonPath('data.status', 'amount_mismatch');
        $this->assertSame(LandingPageOrder::STATUS_PENDING, $order->fresh()->status);
        $this->assertNull(SellerBalance::query()->find($seller['org']->id));
        $this->assertSame('amount_mismatch', data_get($order->fresh()->metadata, 'payment_problems.0.problem'));
    }

    public function test_webhook_body_is_not_trusted_and_wrong_token_is_rejected(): void
    {
        $seller = $this->seller(100000);
        $order = $this->pendingOrder($seller);

        // Wrong callback token → 401, nothing happens.
        $this->ipaymuWebhook($order, 'trx-3', 'wrong-token')->assertStatus(401);
        $this->assertSame(0, Http::recorded()->count());

        // Valid token, body says "berhasil", but iPaymu's API says still pending → not paid.
        $this->fakeIpaymuTransaction('trx-3', (string) $order->reference_id, 100000, status: 0);
        $this->ipaymuWebhook($order, 'trx-3')->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(LandingPageOrder::STATUS_PENDING, $order->fresh()->status);

        $log = PaymentWebhookLog::query()->where('provider', 'ipaymu')->orderBy('id')->first();
        $this->assertFalse($log->signature_valid);
        $this->assertStringNotContainsString('wrong-token', json_encode($log->headers));
    }

    public function test_doku_landing_notification_requires_signature(): void
    {
        $seller = $this->seller(100000);
        $order = $this->pendingOrder($seller);
        $order->forceFill(['provider' => 'doku'])->save();

        app(\App\Services\Hellom\DokuSettingsService::class)->saveConfig(['client_id' => 'client-test', 'secret_key' => 'secret-test', 'callback_token' => 'doku-token']);
        $this->postJson('/api/v1/hellom/webhooks/doku?token=doku-token', [
            'order' => ['invoice_number' => $order->reference_id, 'amount' => 100000, 'status' => 'SUCCESS'],
        ], ['Signature' => 'HMACSHA256=forged', 'Client-Id' => 'client-test', 'Request-Id' => 'r1', 'Request-Timestamp' => now('UTC')->format('Y-m-d\TH:i:s\Z')])
            ->assertStatus(401);
        $this->assertSame(LandingPageOrder::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_one_gateway_payment_cannot_pay_two_orders_and_browser_hints_need_reference(): void
    {
        $seller = $this->seller(100000);
        $first = $this->paidSale($seller, 'trx-shared');

        $second = $this->pendingOrder($seller);
        // Gateway reports the shared transaction (reference of the first order) for the second.
        $this->fakeIpaymuTransaction('trx-shared', (string) $first->reference_id, 100000);
        $this->assertContains(app(LandingPaymentService::class)->handleNotification('ipaymu', (string) $second->reference_id, 'trx-shared'), ['transaction_reused', 'reference_mismatch']);
        $this->assertSame(LandingPageOrder::STATUS_PENDING, $second->fresh()->status);
        $this->assertNull($second->fresh()->gateway_trx_id);

        // A browser hint whose payment the gateway does not tie to this order is refused too.
        $third = $this->pendingOrder($seller);
        app(LandingPaymentService::class)->recordReturnHint($third, 'trx-shared');
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['*/api/v2/transaction' => Http::response(['Data' => ['TransactionId' => 'trx-shared', 'Amount' => 100000, 'Status' => 1]])]);
        $this->assertSame('reference_unknown', app(LandingPaymentService::class)->reconcileOrder($third->fresh()));
        $this->assertSame(LandingPageOrder::STATUS_PENDING, $third->fresh()->status);
    }

    public function test_lost_webhook_is_recovered_by_reconcile_and_old_orders_expire(): void
    {
        $seller = $this->seller(100000);
        $paid = $this->pendingOrder($seller);
        $paid->forceFill(['gateway_trx_id' => 'trx-lost', 'created_at' => now()->subMinutes(10)])->save();
        $this->fakeIpaymuTransaction('trx-lost', (string) $paid->reference_id, 100000);

        $summary = app(LandingPaymentService::class)->reconcilePending(5);
        $this->assertSame(1, $summary['paid']);
        $this->assertSame(LandingPageOrder::STATUS_PAID, $paid->fresh()->status);

        $stale = $this->pendingOrder($seller);
        $stale->forceFill(['expires_at' => now()->subMinute()])->save(); // no transaction id: cannot be paid
        $this->assertSame(1, app(LandingPaymentService::class)->expireDue()['expired']);
        $this->assertSame(LandingPageOrder::STATUS_EXPIRED, $stale->fresh()->status);
        $this->assertFalse($stale->fresh()->canTransitionTo(LandingPageOrder::STATUS_FULFILLED));
    }

    public function test_hellom_fee_always_covers_the_gateway_fee(): void
    {
        app(FinanceSettings::class)->update(['platform_fee_percent' => 1, 'min_margin_flat' => 500]);
        $split = app(FeeCalculator::class)->split(50000, 'va', 4500);

        // 1% = 500 would lose money on a Rp4.500 VA fee → fee raised to 4.500 + 500 margin.
        $this->assertSame(5000, $split['platform_fee']);
        $this->assertSame(45000, $split['seller_net']);
        $this->assertSame(500, $split['hellom_net']);
    }

    public function test_hold_days_keep_the_sale_pending_until_release(): void
    {
        app(FinanceSettings::class)->update(['hold_days' => 3]);
        $seller = $this->seller(100000);
        $order = $this->paidSale($seller);

        $balance = SellerBalance::query()->find($seller['org']->id);
        $this->assertSame(95000, (int) $balance->pending);
        $this->assertSame(0, (int) $balance->available);

        $this->travel(4)->days();
        $this->assertSame(1, app(\App\Services\SellerFinance\SellerLedger::class)->releaseDue());
        $balance->refresh();
        $this->assertSame(0, (int) $balance->pending);
        $this->assertSame(95000, (int) $balance->available);
        $this->assertTrue(app(\App\Services\SellerFinance\SellerLedger::class)->reconcile($seller['org']->id)['ok']);
        $this->assertNotNull($order->fresh()->settlement_eta);
    }

    public function test_status_and_public_page_never_leak_the_delivery_link_before_payment(): void
    {
        $seller = $this->seller(100000);
        $order = $this->pendingOrder($seller);

        $this->getJson("/api/v1/hellom/public/landingpage/orders/{$order->reference_id}/status")
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.download_token', null);
        $this->postJson("/api/v1/hellom/public/landingpage/orders/{$order->reference_id}/returned", ['trx_id' => 'trx-x'])->assertOk();
        $this->assertSame(LandingPageOrder::STATUS_PENDING, $order->fresh()->status); // a return never marks paid
        $this->assertStringNotContainsString('rahasia', json_encode($order->items()->first()->snapshot));
    }
}
