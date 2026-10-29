<?php

namespace Tests\Pos;

use App\Models\ApiToken;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Hpp\HppCostSnapshot;
use App\Models\Hpp\HppProduct;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PosStaff;
use App\Models\PosStaffCashLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

class PosReportsTest extends PosTestCase
{
    use DatabaseTransactions;

    public function test_report_totals_use_paid_amounts_refunds_timezone_and_export_scope(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('R');
        [$headers, $user] = $this->ownerHeaders($org, $outlet->id);
        $product = $this->makeProduct($outlet, 100000);
        $paidAt = Carbon::parse('2026-10-29 17:30:00', 'UTC');

        $paidOrder = $this->createOrder($outlet->tenant_slug, [
            'order_number' => 'REPORT-PAID-1',
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'total_amount' => 100000,
            'subtotal_amount' => 100000,
            'discount_amount' => 10000,
            'points_discount_amount' => 5000,
            'manual_discount_amount' => 5000,
            'service_amount' => 4000,
            'tax_amount' => 9000,
            'rounding_amount' => 0,
            'final_amount' => 93000,
            'paid_at' => $paidAt,
            'created_at' => $paidAt->copy()->subHour(),
        ]);
        $paidOrder->forceFill(['manual_discount_amount' => 5000])->save();
        $paidOrder->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => 100000,
            'qty' => 1,
            'line_total' => 100000,
        ]);

        $refundedOrder = $this->createOrder($outlet->tenant_slug, [
            'order_number' => 'REPORT-REFUND-1',
            'status' => 'completed',
            'payment_status' => 'refunded',
            'payment_method' => 'qris',
            'total_amount' => 50000,
            'subtotal_amount' => 50000,
            'final_amount' => 50000,
            'refund_amount' => 50000,
            'paid_at' => $paidAt,
            'created_at' => $paidAt,
        ]);
        $refundedOrder->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => 50000,
            'qty' => 1,
            'line_total' => 50000,
        ]);

        $this->createOrder($outlet->tenant_slug, [
            'order_number' => 'REPORT-UNPAID-1',
            'status' => 'new',
            'payment_status' => 'unpaid',
            'created_at' => $paidAt,
        ]);
        $this->createOrder($outlet->tenant_slug, [
            'order_number' => 'REPORT-CANCELLED-1',
            'status' => 'cancelled',
            'payment_status' => 'unpaid',
            'created_at' => $paidAt,
            'cancelled_at' => $paidAt,
        ]);
        $otherOutlet = $this->makeOutlet($org);
        $this->createOrder($otherOutlet->tenant_slug, [
            'order_number' => 'REPORT-OTHER-OUTLET-1',
            'payment_status' => 'paid',
            'payment_method' => 'qris',
            'total_amount' => 7000,
            'subtotal_amount' => 7000,
            'final_amount' => 7000,
            'paid_at' => $paidAt,
            'created_at' => $paidAt,
        ]);

        $summary = $this->withHeaders($headers)->getJson('/api/v1/hellom/pos/reports/summary?' . http_build_query([
            'start_date' => '2026-10-30',
            'end_date' => '2026-10-30',
            'timezone' => 'Asia/Jakarta',
            'date_basis' => 'paid_at',
        ]))->assertOk()->json('data');

        $this->assertSame(93000, $summary['summary']['net_collected']);
        $this->assertSame(150000, $summary['summary']['gross_sales']);
        $this->assertSame(20000, $summary['summary']['discount_total']);
        $this->assertSame(130000, $summary['summary']['net_sales']);
        $this->assertSame(50000, $summary['summary']['refunded_total']);
        $this->assertSame(1, $summary['summary']['total_orders']);
        $this->assertSame(1, $summary['summary']['refunded_orders']);
        $this->assertSame(1, $summary['summary']['unpaid_orders']);
        $this->assertSame(1, $summary['summary']['cancelled_orders']);
        $this->assertSame('Asia/Jakarta', $summary['period']['timezone']);

        $allOutlets = $this->withHeaders($headers)->getJson('/api/v1/hellom/pos/reports/summary?' . http_build_query([
            'start_date' => '2026-10-30',
            'end_date' => '2026-10-30',
            'timezone' => 'Asia/Jakarta',
            'scope' => 'all',
        ]))->assertOk()->json('data');
        $this->assertSame('all_outlets', $allOutlets['scope']);
        $this->assertSame(100000, $allOutlets['summary']['net_collected']);
        $this->assertCount(2, $allOutlets['outlet_breakdown']);

        $daily = $this->withHeaders($headers)->getJson('/api/v1/hellom/pos/reports/daily?' . http_build_query([
            'start_date' => '2026-10-30',
            'end_date' => '2026-10-30',
            'timezone' => 'Asia/Jakarta',
        ]))->assertOk()->json('data.daily');
        $this->assertSame('2026-10-30', $daily[0]['date']);
        $this->assertSame(93000, $daily[0]['total_revenue']);

        $transactions = $this->withHeaders($headers)->getJson('/api/v1/hellom/pos/reports/transactions?' . http_build_query([
            'start_date' => '2026-10-30',
            'end_date' => '2026-10-30',
            'timezone' => 'Asia/Jakarta',
        ]))->assertOk()->json('data.transactions');
        $this->assertCount(2, $transactions);
        $this->assertSame(0, $transactions[0]['net_collected']);

        $export = $this->withHeaders($headers)->get('/api/v1/hellom/pos/reports/export?' . http_build_query([
            'start_date' => '2026-10-30',
            'end_date' => '2026-10-30',
            'timezone' => 'Asia/Jakarta',
            'scope' => 'all',
        ]));
        $export->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('Laporan-Keuangan', $export->headers->get('content-disposition'));
        $this->assertNotNull($user->fresh());
    }

    public function test_report_includes_cash_reconciliation_and_hpp_margin_with_coverage(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('M');
        [$headers, $user] = $this->ownerHeaders($org, $outlet->id);
        $product = $this->makeProduct($outlet, 50000);
        $order = $this->createOrder($outlet->tenant_slug, [
            'order_number' => 'REPORT-MARGIN-1',
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'total_amount' => 100000,
            'subtotal_amount' => 100000,
            'final_amount' => 100000,
            'paid_at' => now(),
            'created_at' => now(),
            'user_id' => $user->id,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => 50000,
            'qty' => 2,
            'line_total' => 100000,
        ]);

        $staff = PosStaff::query()->create([
            'tenant_id' => $outlet->tenant_slug,
            'organization_id' => $org->id,
            'linked_user_id' => $user->id,
            'name' => 'Kasir Laporan',
            'role' => 'cashier',
            'employment_status' => 'active',
        ]);
        PosStaffCashLog::query()->create([
            'tenant_id' => $outlet->tenant_slug,
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'opening_cash' => 100000,
            'closing_cash' => 150000,
            'expected_cash' => 150000,
            'difference_cash' => 0,
            'total_cash_sales' => 50000,
            'total_transactions' => 1,
            'started_at' => now()->subHour(),
            'closed_at' => now(),
            'status' => 'closed',
        ]);

        $hpp = HppProduct::query()->create([
            'organization_id' => $org->id,
            'name' => $product->name,
            'pos_product_id' => $product->id,
            'cost_full' => 15000,
            'cost_updated_at' => now()->subDay(),
        ]);
        HppCostSnapshot::query()->create([
            'product_id' => $hpp->id,
            'snapshot_date' => now()->toDateString(),
            'cost_material' => 12000,
            'cost_packaging' => 3000,
            'cost_full' => 15000,
            'selling_price' => 50000,
            'margin_pct' => 70,
        ]);

        $query = http_build_query([
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'timezone' => config('app.timezone'),
        ]);
        $shiftReport = $this->withHeaders($headers)->getJson("/api/v1/hellom/pos/reports/shifts?{$query}")
            ->assertOk()->json('data');
        $this->assertSame(1, $shiftReport['summary']['closed_shifts']);
        $this->assertSame(0, $shiftReport['summary']['difference_cash']);
        $this->assertSame('Kasir Laporan', $shiftReport['shifts'][0]['staff_name']);
        $this->assertSame(1, $shiftReport['pagination']['total']);

        $this->withHeaders($headers)->get("/api/v1/hellom/pos/reports/export?{$query}")
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $marginReport = $this->withHeaders($headers)->getJson("/api/v1/hellom/pos/reports/margin?{$query}")
            ->assertOk()->json('data');
        $this->assertSame(100, $marginReport['coverage_pct']);
        $this->assertSame(70000, $marginReport['estimated_profit']);
        $this->assertSame(70, $marginReport['gross_margin_pct']);
    }

    private function ownerHeaders(Organization $org, int $outletId): array
    {
        $user = User::factory()->create([
            'name' => 'Report Owner',
            'email' => Str::lower(Str::random(10)) . '@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'tenant_admin',
            'current_organization_id' => $org->id,
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        Entitlement::query()->firstOrCreate(
            ['organization_id' => $org->id, 'app_id' => $app->id],
            ['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]
        );
        $plain = Str::random(40);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'report-tests',
            'token_hash' => hash('sha256', $plain),
        ]);

        return [[
            'Authorization' => 'Bearer ' . $plain,
            'Accept' => 'application/json',
            'X-Outlet-Id' => (string) $outletId,
        ], $user];
    }

    private function createOrder(string $tenant, array $attributes): Order
    {
        return Order::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $tenant,
            'order_number' => 'REPORT-' . Str::upper(Str::random(12)),
            'status' => 'completed',
            'total_amount' => 0,
            'subtotal_amount' => 0,
            'discount_amount' => 0,
            'points_discount_amount' => 0,
            'manual_discount_amount' => 0,
            'service_amount' => 0,
            'tax_amount' => 0,
            'rounding_amount' => 0,
            'final_amount' => 0,
            'refund_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
