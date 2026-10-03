<?php

namespace Tests\Finance;

use App\Models\CheckoutIntent;
use App\Models\DigitalProduct;
use App\Models\FinanceJournalEntry;
use App\Models\FinanceJournalLine;
use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;
use App\Models\PlatformFinanceLedger;
use App\Models\ProductPurchase;
use App\Models\User;
use App\Services\Finance\FinanceJournal;
use App\Services\SellerFinance\FeeCalculator;
use App\Services\SellerFinance\SellerLedger;
use App\Services\SellerFinance\WithdrawalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Landing\SellerFinanceTestCase;

/** Fase 3: double-entry journal next to the seller ledger (append-only, idempotent, balanced). */
class FinanceJournalTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    /** Account balance from entries of one organization (shared accounts also hold other tests' rows). */
    private function balance(string $account, int $organizationId): int
    {
        return (int) FinanceJournalLine::query()->where('account', $account)
            ->whereHas('entry', fn ($q) => $q->where('organization_id', $organizationId))->sum('amount');
    }

    private function assertEveryEntryBalanced(): void
    {
        $unbalanced = FinanceJournalLine::query()->selectRaw('entry_id, SUM(amount) AS total')->groupBy('entry_id')->havingRaw('SUM(amount) <> 0')->count();
        $this->assertSame(0, $unbalanced);
    }

    public function test_sale_release_withdrawal_and_payout_are_journaled_and_match_the_seller_ledger(): void
    {
        $seller = $this->seller(100000);
        $orgId = (int) $seller['org']->id;
        $order = $this->paidSale($seller);

        // Same webhook again: no new journal entries.
        $entries = FinanceJournalEntry::query()->count();
        $this->fakeIpaymuTransaction('trx-' . $order->id, (string) $order->reference_id, (int) $order->amount);
        $this->ipaymuWebhook($order, 'trx-' . $order->id)->assertOk();
        $this->assertSame($entries, FinanceJournalEntry::query()->count());

        $fee = (int) $order->commission_amount;
        $gatewayFee = (int) $order->gateway_fee_amount;
        $this->assertSame(100000 - $gatewayFee, $this->balance('gateway:ipaymu', $orgId));
        $this->assertSame(-$fee, $this->balance('revenue:platform_fee', $orgId));
        $this->assertSame($gatewayFee, $this->balance('expense:gateway_fee', $orgId));
        // hold_days = 0: released at once.
        $this->assertSame(0, $this->balance("seller:{$orgId}:pending", $orgId));
        $this->assertSame(-(100000 - $fee), $this->balance("seller:{$orgId}:available", $orgId));

        $withdrawals = app(WithdrawalService::class);
        $withdrawal = $withdrawals->request($seller['org'], $seller['user'], 60000);
        $this->assertSame(-60000, $this->balance("seller:{$orgId}:processing", $orgId));
        $withdrawal->forceFill(['fee_amount' => 2500, 'net_amount' => 57500])->save();
        $withdrawals->markPaid($withdrawal, User::query()->create(['name' => 'Admin', 'email' => Str::random(8) . '@example.test', 'password' => 'x', 'role' => 'super_admin']));

        $this->assertSame(0, $this->balance("seller:{$orgId}:processing", $orgId));
        $this->assertSame(-57500, $this->balance('bank:hellom', $orgId));
        $this->assertSame(-2500, $this->balance('revenue:withdrawal_fee', $orgId));
        $this->assertEveryEntryBalanced();

        $this->artisan('finance:journal-reconcile', ['--organization' => $orgId])->assertSuccessful();
        $computed = app(SellerLedger::class)->computed($orgId);
        $this->assertSame($computed['available'], -$this->balance("seller:{$orgId}:available", $orgId));
    }

    public function test_backfill_reports_first_then_writes_history_once(): void
    {
        $seller = $this->seller(100000);
        $this->paidSale($seller);
        $orgId = (int) $seller['org']->id;
        // History before the journal existed.
        FinanceJournalEntry::query()->where('organization_id', $orgId)->delete();

        $this->artisan('finance:journal-backfill')->assertSuccessful();
        $this->assertSame(0, FinanceJournalEntry::query()->where('organization_id', $orgId)->count(), 'report mode writes nothing');

        $this->artisan('finance:journal-backfill', ['--force' => true])->assertSuccessful();
        $written = FinanceJournalEntry::query()->where('organization_id', $orgId)->count();
        $this->assertSame(3, $written); // sale, platform fee, release

        $this->artisan('finance:journal-backfill', ['--force' => true])->assertSuccessful();
        $this->assertSame($written, FinanceJournalEntry::query()->where('organization_id', $orgId)->count());
        $this->artisan('finance:journal-reconcile', ['--organization' => $orgId])->assertSuccessful();
        $this->assertEveryEntryBalanced();
    }

    public function test_own_product_paid_and_refunded(): void
    {
        $user = User::query()->create(['name' => 'Dina', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => 'x', 'role' => 'member']);
        $product = DigitalProduct::query()->create(['slug' => 'kelas-' . Str::lower(Str::random(6)), 'name' => 'Kelas', 'category' => 'course', 'type' => 'paid', 'price' => 150000, 'currency' => 'IDR', 'is_published' => true]);
        $purchase = ProductPurchase::query()->create(['user_id' => $user->id, 'product_id' => $product->id, 'transaction_code' => 'TRX-' . Str::random(8),
            'amount_paid' => 150000, 'payment_method' => 'qris', 'payment_status' => 'pending', 'payment_gateway' => 'ipaymu']);
        $this->assertFalse(FinanceJournal::exists("product_purchase:{$purchase->id}:paid"));

        $purchase->forceFill(['payment_status' => 'paid', 'paid_at' => now()])->save();
        $fee = app(FeeCalculator::class)->estimateGatewayFee(150000, 'qris');
        $entry = FinanceJournalEntry::query()->where('event_key', "product_purchase:{$purchase->id}:paid")->firstOrFail();
        $this->assertSame('digital_product', $entry->source);
        $this->assertSame(-150000, (int) $entry->lines()->where('account', 'revenue:digital_product')->value('amount'));
        $this->assertSame(150000 - $fee, (int) $entry->lines()->where('account', 'gateway:ipaymu')->value('amount'));

        $purchase->forceFill(['payment_status' => 'refunded'])->save();
        $this->assertTrue(FinanceJournal::exists("product_purchase:{$purchase->id}:refunded"));
        $this->assertSame(0, (int) FinanceJournalLine::query()->whereHas('entry', fn ($q) => $q->where('source_id', $purchase->id)->where('source', 'digital_product'))
            ->where('account', 'revenue:digital_product')->sum('amount'));
        $this->assertEveryEntryBalanced();
    }

    public function test_subscriptions_and_top_ups(): void
    {
        $seller = $this->seller();
        $orgId = (int) $seller['org']->id;
        $wallet = OrganizationWallet::query()->firstOrCreate(['organization_id' => $orgId], ['available_balance' => 0, 'pending_balance' => 0]);

        // Top-up through iPaymu, then a subscription paid from the wallet.
        OrganizationWalletTransaction::query()->create(['organization_id' => $orgId, 'wallet_id' => $wallet->id, 'type' => 'payment_credit', 'direction' => 'credit',
            'amount' => 200000, 'balance_after' => 200000, 'reference_type' => 'ipaymu_event', 'reference_id' => 'success', 'external_ref' => 'r-' . Str::random(6)]);
        OrganizationWalletTransaction::query()->create(['organization_id' => $orgId, 'wallet_id' => $wallet->id, 'type' => 'app_checkout_debit', 'direction' => 'debit',
            'amount' => 49000, 'balance_after' => 151000, 'reference_type' => 'checkout_intents', 'reference_id' => '1', 'external_ref' => 'w-' . Str::random(6)]);
        $this->assertSame(-151000, $this->balance("wallet:{$orgId}", $orgId));

        // The wallet-paid intent is not counted twice; a gateway-paid one is.
        $app = \App\Models\AppCatalog::query()->firstOrCreate(['slug' => 'landing_builder'], ['name' => 'Hellom Page', 'is_active' => true]);
        $plan = \App\Models\Plan::query()->create(['slug' => 'pro_' . Str::lower(Str::random(6)), 'name' => 'Pro', 'type' => 'subscription', 'price' => 49000, 'billing_cycles' => ['monthly'], 'is_active' => true, 'is_visible' => true]);
        $base = ['organization_id' => $orgId, 'user_id' => $seller['user']->id, 'app_id' => $app->id, 'plan_id' => $plan->id, 'status' => 'pending', 'currency' => 'IDR'];
        $walletIntent = CheckoutIntent::query()->create($base + ['intent_token' => Str::random(20), 'amount' => 49000]);
        $walletIntent->forceFill(['status' => 'confirmed', 'metadata' => ['wallet_payment' => ['charged_amount' => 49000]]])->save();
        $gatewayIntent = CheckoutIntent::query()->create($base + ['intent_token' => Str::random(20), 'amount' => 490000]);
        $gatewayIntent->forceFill(['status' => 'confirmed', 'metadata' => ['ipaymu' => ['trx_id' => 't-9', 'channel' => 'bca']]])->save();

        $this->assertFalse(FinanceJournal::exists("checkout_intent:{$walletIntent->id}"));
        $entry = FinanceJournalEntry::query()->where('event_key', "checkout_intent:{$gatewayIntent->id}")->firstOrFail();
        $this->assertSame('ipaymu', $entry->provider);
        $this->assertSame(-(49000 + 490000), $this->balance('revenue:subscription', $orgId));
        $this->assertEveryEntryBalanced();
    }

    public function test_journal_rejects_unbalanced_entries_and_ignores_duplicates(): void
    {
        $journal = app(FinanceJournal::class);
        $key = 'test:' . Str::random(8);
        $entry = ['event_type' => 'test', 'source' => 'seller_finance'];

        $this->assertNotNull($journal->post($key, $entry, ['bank:hellom' => 1000, 'equity:opening' => -1000]));
        $this->assertNull($journal->post($key, $entry, ['bank:hellom' => 1000, 'equity:opening' => -1000]));
        $this->expectException(InvalidArgumentException::class);
        $journal->post('test:' . Str::random(8), $entry, ['bank:hellom' => 1000, 'equity:opening' => -999]);
    }

    public function test_platform_ledger_is_idempotent_per_reference_and_keeps_the_running_balance(): void
    {
        $start = PlatformFinanceLedger::getCurrentBalance();
        $first = PlatformFinanceLedger::recordRevenue('test_fee', 5000, null, 'landing_page_orders', 987654321);
        $again = PlatformFinanceLedger::recordRevenue('test_fee', 5000, null, 'landing_page_orders', 987654321);
        $expense = PlatformFinanceLedger::recordExpense('test_cost', 1200, 'landing_page_orders', 987654321);

        $this->assertSame($first->id, $again->id);
        $this->assertSame($start + 5000, (int) $first->balance_after);
        $this->assertSame($start + 5000 - 1200, (int) $expense->balance_after);
        $this->assertSame($start + 3800, PlatformFinanceLedger::getCurrentBalance());
    }
}
