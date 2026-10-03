<?php

namespace Tests\Landing;

use App\Models\SellerBalance;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWithdrawal;
use App\Services\SellerFinance\SellerLedger;
use Illuminate\Support\Facades\DB;

/**
 * Two withdrawals at the same moment from two real PHP processes (separate DB connections).
 * Balance 95.000, each asks 70.000: exactly one may pass, the balance never goes below zero.
 * Data is committed, so the test removes it afterwards.
 */
class ConcurrentWithdrawalTest extends SellerFinanceTestCase
{
    private ?array $seller = null;

    public function test_two_concurrent_withdrawals_never_overdraw(): void
    {
        $this->seller = $this->seller(100000);
        $this->paidSale($this->seller);
        $this->assertSame(95000, (int) SellerBalance::query()->find($this->seller['org']->id)->available);

        $startAt = microtime(true) + 3.0;
        $worker = base_path('tests/Landing/fixtures/withdraw_worker.php');
        $processes = [];
        $pipes = [];
        foreach ([1, 2] as $i) {
            $cmd = [PHP_BINARY, $worker, (string) $this->seller['org']->id, (string) $this->seller['user']->id, '70000', sprintf('%.3F', $startAt)];
            $processes[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path());
        }
        $results = [];
        foreach ($processes as $i => $process) {
            $out = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            proc_close($process);
            $results[] = json_decode((string) $out, true) ?? ['ok' => false, 'code' => 'CRASH', 'message' => $out . $err];
        }

        $ok = array_values(array_filter($results, fn ($r) => $r['ok'] ?? false));
        $failed = array_values(array_filter($results, fn ($r) => !($r['ok'] ?? false)));
        $this->assertCount(1, $ok, json_encode($results));
        $this->assertSame('INSUFFICIENT_BALANCE', $failed[0]['code'] ?? null, json_encode($results));

        $balance = SellerBalance::query()->find($this->seller['org']->id);
        $this->assertSame(25000, (int) $balance->available);
        $this->assertSame(70000, (int) $balance->processing);
        $this->assertTrue(app(SellerLedger::class)->reconcile($this->seller['org']->id)['ok']);
    }

    protected function tearDown(): void
    {
        if ($this->seller) {
            $orgId = $this->seller['org']->id;
            $orderIds = DB::table('landing_page_orders')->where('organization_id', $orgId)->pluck('id');
            // Ledger rows are append-only in the app; test cleanup removes them directly.
            DB::table('seller_balance_ledger')->where('organization_id', $orgId)->delete();
            DB::table('finance_journal_entries')->where('organization_id', $orgId)->delete(); // lines cascade
            DB::table('seller_withdrawals')->where('organization_id', $orgId)->delete();
            DB::table('seller_balances')->where('organization_id', $orgId)->delete();
            DB::table('landing_order_items')->whereIn('order_id', $orderIds)->delete();
            DB::table('landing_page_orders')->where('organization_id', $orgId)->delete();
            DB::table('platform_finance_ledgers')->where('reference_type', 'landing_page_orders')->whereIn('reference_id', $orderIds)->delete();
            DB::table('landing_blocks')->where('organization_id', $orgId)->delete();
            DB::table('organization_landing_pages')->where('organization_id', $orgId)->delete();
            DB::table('organization_payout_profiles')->where('organization_id', $orgId)->delete();
            DB::table('payment_webhook_logs')->where('reference', 'like', 'lps_%')->where('received_at', '>=', now()->subHour())->delete();
            DB::table('payment_events')->where('organization_id', $orgId)->delete();
            DB::table('api_tokens')->where('user_id', $this->seller['user']->id)->delete();
            DB::table('organization_user')->where('organization_id', $orgId)->delete();
            DB::table('users')->where('id', $this->seller['user']->id)->delete();
            DB::table('organizations')->where('id', $orgId)->delete();
            foreach (['hellom_ipaymu_va', 'hellom_ipaymu_api_key', 'hellom_ipaymu_callback_token', 'hellom_ipaymu_is_production', 'hellom_ipaymu_payment_methods', 'seller_finance_settings'] as $key) {
                DB::table('system_settings')->where('key', $key)->delete();
            }
        }
        parent::tearDown();
    }
}
