<?php

namespace Tests\Pos;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MemberPointTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\PosLoyaltySetting;
use App\Models\PosMember;
use App\Models\Product;
use App\Services\Pos\LoyaltyService;
use App\Services\Pos\MemberService;
use Illuminate\Support\Facades\DB;

/**
 * Two cashiers redeem the same member's points at the same moment (two real PHP
 * processes, two DB connections). The member row lock lets exactly one through; the
 * balance never goes negative. Data is committed, so this test cleans up after itself.
 */
class ConcurrentRedeemTest extends PosTestCase
{
    private ?Organization $org = null;

    public function test_two_concurrent_redemptions_never_make_balance_negative(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('C');
        $this->org = $org;
        $this->loyalty($org, ['enabled' => true, 'points_per_amount' => 1000, 'redeem_value_per_point' => 100, 'min_redeem_points' => 1]);
        $product = $this->makeProduct($outlet, 50000);
        [$member] = app(MemberService::class)->register($org, 'Paralel', '081277770000');
        app(LoyaltyService::class)->adjust($member, 100, 'Saldo awal tes', null);

        $startAt = microtime(true) + 3.0; // both workers boot, then fire together
        $worker = base_path('tests/Pos/fixtures/redeem_worker.php');
        $processes = [];
        foreach ([1, 2] as $i) {
            $cmd = [PHP_BINARY, $worker, (string) $outlet->id, (string) $member->id, (string) $product->id, '70', sprintf('%.3F', $startAt)];
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
        $this->assertSame('POINTS_INSUFFICIENT', $failed[0]['code'] ?? null, json_encode($results));

        $fresh = PosMember::query()->findOrFail($member->id);
        $this->assertSame(30, (int) $fresh->redeemable_points);
        $this->assertSame(30, (int) MemberPointTransaction::query()->where('member_id', $member->id)->sum('points'));
    }

    protected function tearDown(): void
    {
        if ($this->org) {
            $org = $this->org;
            $outletIds = Outlet::withTrashed()->where('organization_id', $org->id)->pluck('id');
            $slugs = Outlet::withTrashed()->where('organization_id', $org->id)->pluck('tenant_slug')->push($org->pos_tenant_slug)->unique();
            $orderIds = Order::withoutGlobalScope('tenant')->withTrashed()->whereIn('tenant_id', $slugs)->pluck('id');

            DB::table('order_item_options')->whereIn('order_item_id', OrderItem::query()->whereIn('order_id', $orderIds)->pluck('id'))->delete();
            OrderItem::query()->whereIn('order_id', $orderIds)->delete();
            MemberPointTransaction::query()->where('organization_id', $org->id)->delete();
            Order::withoutGlobalScope('tenant')->withTrashed()->whereIn('id', $orderIds)->forceDelete();
            DB::table('table_bills')->whereIn('outlet_id', $outletIds)->delete();
            DB::table('order_number_sequences')->whereIn('tenant_id', $slugs)->delete();
            PosMember::query()->where('organization_id', $org->id)->delete();
            Product::withoutGlobalScope('tenant')->whereIn('tenant_id', $slugs)->delete();
            Category::withoutGlobalScope('tenant')->whereIn('tenant_id', $slugs)->delete();
            PosLoyaltySetting::query()->whereIn('tenant_id', $slugs)->delete();
            AuditLog::query()->where('organization_id', $org->id)->delete();
            Outlet::withTrashed()->where('organization_id', $org->id)->forceDelete();
            $org->delete();
        }

        parent::tearDown();
    }
}
