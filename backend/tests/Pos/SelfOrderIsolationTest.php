<?php

namespace Tests\Pos;

use App\Models\Order;
use App\Services\Pos\OrderService;
use App\Services\Pos\PricingException;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/** A QR code from outlet A can never order at outlet B or at another tenant. */
class SelfOrderIsolationTest extends PosTestCase
{
    use DatabaseTransactions;

    public function test_qr_of_outlet_a_cannot_order_products_of_outlet_b_or_other_tenant(): void
    {
        ['org' => $org, 'outlet' => $outletA] = $this->makeOrganization('A');
        $outletB = $this->makeOutlet($org);
        ['outlet' => $otherTenantOutlet] = $this->makeOrganization('Z');

        $tableA = $this->makeTable($outletA);
        $productB = $this->makeProduct($outletB, 10000);
        $productOther = $this->makeProduct($otherTenantOutlet, 10000);

        foreach ([$productB, $productOther] as $foreign) {
            $response = $this->postJson('/api/v1/hellom/pos/customer/order', [
                'table_token' => $tableA->public_id,
                'items' => [['product_id' => $foreign->id, 'quantity' => 1]],
            ]);
            $response->assertStatus(409)->assertJsonPath('error.code', 'CART_CHANGED');
            $response->assertJsonPath('error.problems.0.reason', 'not_found');
        }

        $this->assertSame(0, Order::withoutGlobalScope('tenant')->where('dining_table_id', $tableA->id)->count());
    }

    public function test_table_of_outlet_b_is_rejected_at_outlet_a(): void
    {
        ['org' => $org, 'outlet' => $outletA] = $this->makeOrganization('A');
        $outletB = $this->makeOutlet($org);
        $tableB = $this->makeTable($outletB);
        $product = $this->makeProduct($outletA, 10000);

        $this->expectException(PricingException::class);
        app(OrderService::class)->create($outletA, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'dining_table' => $tableB,
        ]);
    }

    public function test_rotated_or_inactive_token_stops_working(): void
    {
        ['outlet' => $outlet] = $this->makeOrganization('A');
        $table = $this->makeTable($outlet);
        $product = $this->makeProduct($outlet, 10000);
        $oldToken = $table->public_id;

        $table->forceFill(['public_id' => $table::newPublicToken(), 'token_rotated_at' => now()])->save();

        $this->getJson("/api/v1/hellom/pos/customer/menu/{$oldToken}")->assertNotFound();
        $this->postJson('/api/v1/hellom/pos/customer/order', [
            'table_token' => $oldToken,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertNotFound();

        $this->getJson("/api/v1/hellom/pos/customer/menu/{$table->public_id}")
            ->assertOk()
            ->assertJsonPath('data.outlet.id', $outlet->id);
    }

    public function test_closed_outlet_and_pending_cap_are_enforced(): void
    {
        ['outlet' => $outlet] = $this->makeOrganization('A', ['self_order' => ['accept_orders' => true, 'require_confirmation' => true, 'max_pending_per_table' => 1]]);
        $table = $this->makeTable($outlet);
        $product = $this->makeProduct($outlet, 10000);
        $payload = ['table_token' => $table->public_id, 'items' => [['product_id' => $product->id, 'quantity' => 1]]];

        $this->postJson('/api/v1/hellom/pos/customer/order', $payload)->assertCreated()->assertJsonPath('data.order.status', 'new');
        $this->postJson('/api/v1/hellom/pos/customer/order', $payload)->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_PENDING');

        // No opening slot today → closed with a friendly message.
        $outlet->forceFill(['settings' => array_merge($outlet->settings ?? [], ['opening_hours' => ['mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []]])])->save();
        $this->postJson('/api/v1/hellom/pos/customer/order', $payload)->assertStatus(423)->assertJsonPath('error.code', 'OUTLET_CLOSED');
        $this->getJson("/api/v1/hellom/pos/customer/menu/{$table->public_id}")->assertJsonPath('data.outlet.status.can_order', false);
    }

    public function test_changed_price_is_reported_not_charged(): void
    {
        ['outlet' => $outlet] = $this->makeOrganization('A');
        $table = $this->makeTable($outlet);
        $product = $this->makeProduct($outlet, 12000);

        $this->postJson('/api/v1/hellom/pos/customer/order', [
            'table_token' => $table->public_id,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'expected_unit_price' => 10000]],
        ])->assertStatus(409)
            ->assertJsonPath('error.problems.0.reason', 'price')
            ->assertJsonPath('error.problems.0.old_price', 10000)
            ->assertJsonPath('error.problems.0.new_price', 12000);
    }
}
