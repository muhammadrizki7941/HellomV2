<?php

namespace Tests\Finance;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Landing\SellerFinanceTestCase;

/** Fase 4: super admin finance dashboard reads the journal (summary per gateway + transactions). */
class AdminFinanceDashboardTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    private function getAs(User $user, string $uri): TestResponse
    {
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 'test', 'token_hash' => hash('sha256', $plain)]);

        return $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer ' . $plain, 'Accept' => 'application/json'])->getJson('/api/v1/hellom' . $uri);
    }

    private function superAdmin(): User
    {
        return User::query()->create(['name' => 'Admin', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => 'x', 'role' => 'super_admin']);
    }

    public function test_only_super_admin_can_read_the_finance_dashboard(): void
    {
        $seller = $this->seller();

        $this->getAs($seller['user'], '/admin/finance-journal/summary')->assertForbidden();
        $this->getAs($seller['user'], '/admin/finance-journal/transactions')->assertForbidden();
    }

    public function test_summary_and_transactions_come_from_the_journal(): void
    {
        $seller = $this->seller(100000);
        $orgId = (int) $seller['org']->id;
        $order = $this->paidSale($seller);

        Cache::forget('finance:gateway-balance:ipaymu');
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake(['*/api/v2/balance' => Http::response(['Status' => 200, 'Data' => ['MerchantBalance' => 750000]])]);

        $summary = $this->getAs($this->superAdmin(), '/admin/finance-journal/summary?days=7&refresh=1')->assertOk()->json('data');

        $ipaymu = collect($summary['providers'])->firstWhere('provider', 'ipaymu');
        $this->assertGreaterThanOrEqual(100000, $ipaymu['gross']);
        $this->assertSame(750000, $ipaymu['live_balance']['available']);
        $this->assertNull(collect($summary['providers'])->firstWhere('provider', 'manual')['live_balance']);
        $this->assertCount(7, $summary['trend']);
        $top = collect($summary['top_sellers'])->firstWhere('organization_id', $orgId);
        $this->assertSame(100000, $top['gross']);
        $this->assertSame((int) $order->commission_amount, $top['platform_fee']);
        $this->assertSame($summary['totals']['revenue'] - $summary['totals']['gateway_fees'] - $summary['totals']['adjustments'], $summary['totals']['hellom_net']);

        $list = $this->getAs($this->superAdmin(), "/admin/finance-journal/transactions?organization_id={$orgId}&event_type=sale")->assertOk()->json('data');
        $this->assertSame(1, $list['pagination']['total']);
        $sale = $list['items'][0];
        $this->assertSame('ipaymu', $sale['provider']);
        $this->assertSame(100000, $sale['amount']);
        $this->assertSame(0, array_sum(array_column($sale['lines'], 'amount')));

        $none = $this->getAs($this->superAdmin(), "/admin/finance-journal/transactions?organization_id={$orgId}&provider=xendit")->assertOk()->json('data');
        $this->assertSame(0, $none['pagination']['total']);
    }
}
