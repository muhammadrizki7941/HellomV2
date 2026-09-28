<?php

namespace Tests\Pos;

use App\Models\MemberPointTransaction;
use App\Models\Order;
use App\Models\PosMember;
use App\Services\Pos\LoyaltyService;
use App\Services\Pos\MemberService;
use App\Services\Pos\OrderService;
use App\Services\Pos\PricingException;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/** Points only after payment; reversed on refund/void; redemption needs the member's name. */
class PointsLifecycleTest extends PosTestCase
{
    use DatabaseTransactions;

    public function test_no_points_before_paid_and_reversed_after_refund(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('A');
        $this->loyalty($org, ['enabled' => true, 'points_per_amount' => 1000, 'min_spend_amount' => 0]);
        $product = $this->makeProduct($outlet, 25000);
        [$member] = app(MemberService::class)->register($org, 'Dewi', '081300001111');
        $orders = app(OrderService::class);

        $order = $orders->create($outlet, ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'member' => $member]);
        $orders->transition($order, Order::STATUS_ACCEPTED);
        $orders->transition($order, Order::STATUS_COMPLETED); // kitchen done, still unpaid
        $this->assertSame(0, (int) $member->fresh()->redeemable_points, 'no points before payment');

        $orders->markPaid($order, 'cash', 50000);
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame(50, (int) $member->fresh()->redeemable_points);
        $this->assertSame(50, (int) $order->fresh()->points_earned);

        // Paying twice is rejected; points are not counted twice.
        try {
            $orders->markPaid($order, 'cash', 50000);
            $this->fail('second payment accepted');
        } catch (PricingException $e) {
            $this->assertSame('ORDER_ALREADY_PAID', $e->errorCode);
        }
        app(LoyaltyService::class)->earnForOrder($order->fresh());
        $this->assertSame(50, (int) $member->fresh()->redeemable_points);

        $orders->refund($order, 'Salah input');
        $this->assertSame(0, (int) $member->fresh()->redeemable_points);
        $this->assertSame(0, (int) $member->fresh()->total_orders);
        $this->assertSame(0, (int) MemberPointTransaction::query()->where('member_id', $member->id)->sum('points'));
    }

    public function test_cancelled_order_returns_redeemed_points_and_stock(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('A');
        $this->loyalty($org, ['enabled' => true, 'points_per_amount' => 1000, 'redeem_value_per_point' => 100, 'min_redeem_points' => 10]);
        $product = $this->makeProduct($outlet, 30000, ['track_stock' => true, 'stock' => 5]);
        [$member] = app(MemberService::class)->register($org, 'Rina', '081399998888');
        app(LoyaltyService::class)->adjust($member, 100, 'Saldo awal tes', null);

        $orders = app(OrderService::class);
        try {
            $orders->create($outlet, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'member' => $member, 'redeem_points' => 50, 'verification' => ['confirm_member_name' => 'Orang Lain']]);
            $this->fail('redeem without matching name accepted');
        } catch (PricingException $e) {
            $this->assertSame('MEMBER_NAME_MISMATCH', $e->errorCode);
        }

        $order = $orders->create($outlet, ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'member' => $member, 'redeem_points' => 50, 'verification' => ['confirm_member_name' => '  rina ']]);
        $this->assertSame(5000, (int) $order->points_discount_amount);
        $this->assertSame(55000, (int) $order->final_amount);
        $this->assertSame(50, (int) $member->fresh()->redeemable_points);
        $this->assertSame(3, (int) $product->fresh()->stock);

        $orders->cancel($order, 'Pelanggan batal');
        $this->assertSame(100, (int) $member->fresh()->redeemable_points);
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);

        // A cancelled order cannot move again, and a paid order cannot be cancelled.
        $this->expectException(PricingException::class);
        $orders->transition($order->fresh(), Order::STATUS_PREPARING);
    }

    public function test_illegal_status_transitions_are_rejected(): void
    {
        ['outlet' => $outlet] = $this->makeOrganization('A');
        $product = $this->makeProduct($outlet, 10000);
        $orders = app(OrderService::class);
        $order = $orders->create($outlet, ['items' => [['product_id' => $product->id, 'quantity' => 1]]]);

        $orders->transition($order, Order::STATUS_PREPARING);
        $this->assertNotNull($order->fresh()->confirmed_at);
        try {
            $orders->transition($order, Order::STATUS_ACCEPTED); // backwards
            $this->fail('backwards transition accepted');
        } catch (PricingException $e) {
            $this->assertSame('ILLEGAL_TRANSITION', $e->errorCode);
        }

        $orders->markPaid($order, 'qris', 10000);
        try {
            $orders->cancel($order, 'coba');
            $this->fail('paid order cancelled');
        } catch (PricingException $e) {
            $this->assertSame('ORDER_ALREADY_PAID', $e->errorCode);
        }
    }

    public function test_table_bill_collects_self_order_and_cashier_orders(): void
    {
        ['outlet' => $outlet] = $this->makeOrganization('A', ['self_order' => ['accept_orders' => true, 'require_confirmation' => true, 'max_pending_per_table' => 5]]);
        $table = $this->makeTable($outlet);
        $product = $this->makeProduct($outlet, 20000);

        $this->postJson('/api/v1/hellom/pos/customer/order', ['table_token' => $table->public_id, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $orders = app(OrderService::class);
        $cashierOrder = $orders->create($outlet, ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'dining_table' => $table]);

        $bill = $cashierOrder->fresh()->tableBill;
        $this->assertNotNull($bill);
        $this->getJson("/api/v1/hellom/pos/customer/table/{$table->public_id}/orders")
            ->assertOk()
            ->assertJsonCount(2, 'data.orders')
            ->assertJsonPath('data.bill.unpaid_amount', 60000);

        $result = $orders->payBill($bill, 'cash', 100000);
        $this->assertSame(40000, $result['change']);
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(0, Order::withoutGlobalScope('tenant')->where('table_bill_id', $bill->id)->where('payment_status', 'unpaid')->count());
    }

    public function test_ledger_matches_cached_balance(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('A');
        $this->loyalty($org, ['enabled' => true, 'points_per_amount' => 1000]);
        [$member] = app(MemberService::class)->register($org, 'Tono', '081200000001');
        $loyalty = app(LoyaltyService::class);
        $loyalty->adjust($member, 30, 'Bonus', null);
        $loyalty->adjust($member, -10, 'Koreksi', null);

        $fresh = PosMember::query()->find($member->id);
        $this->assertSame(['member_id' => $member->id, 'cached' => 20, 'ledger' => 20, 'ok' => true], $loyalty->reconcile($fresh));
    }
}
