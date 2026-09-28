<?php

namespace Tests\Pos;

use App\Models\Order;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Services\Pos\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/** Self-order and cashier price the same cart identically (tax, service, rounding, add-ons). */
class PricingParityTest extends PosTestCase
{
    use DatabaseTransactions;

    public function test_self_order_total_equals_cashier_total_for_same_cart(): void
    {
        ['outlet' => $outlet] = $this->makeOrganization('A', [
            'pricing' => ['tax_percent' => 11, 'service_percent' => 5, 'rounding' => 100],
            'self_order' => ['accept_orders' => true, 'require_confirmation' => false, 'max_pending_per_table' => 5],
        ]);
        $table = $this->makeTable($outlet);
        $coffee = $this->makeProduct($outlet, 18500);
        $rice = $this->makeProduct($outlet, 27333);

        $size = ProductOption::query()->create(['product_id' => $coffee->id, 'name' => 'Ukuran', 'type' => 'single', 'is_required' => true, 'is_active' => true, 'sort_order' => 1]);
        $large = ProductOptionValue::query()->create(['product_option_id' => $size->id, 'name' => 'Besar', 'price_delta' => 4000, 'is_active' => true, 'sort_order' => 1]);

        $cart = [
            ['product_id' => $coffee->id, 'quantity' => 2, 'options' => [['option_id' => $size->id, 'value_id' => $large->id]]],
            ['product_id' => $rice->id, 'quantity' => 3],
        ];

        $self = $this->postJson('/api/v1/hellom/pos/customer/order', ['table_token' => $table->public_id, 'items' => $cart])
            ->assertCreated()
            ->assertJsonPath('data.order.status', 'accepted') // straight to kitchen when confirmation is off
            ->json('data.order');

        $cashier = app(OrderService::class)->create($outlet, ['items' => $cart, 'source' => OrderService::SOURCE_POS]);

        $selfOrder = Order::withoutGlobalScope('tenant')->findOrFail($self['id']);
        foreach (['subtotal_amount', 'service_amount', 'tax_amount', 'rounding_amount', 'final_amount'] as $field) {
            $this->assertSame((int) $cashier->{$field}, (int) $selfOrder->{$field}, $field);
        }

        // subtotal 2×22.500 + 3×27.333 = 126.999; service 5% = 6.350; tax 11% of 133.349 = 14.668
        // → 148.017, rounded to 100 → 148.000
        $this->assertSame(126999, (int) $cashier->subtotal_amount);
        $this->assertSame(6350, (int) $cashier->service_amount);
        $this->assertSame(14668, (int) $cashier->tax_amount);
        $this->assertSame(148000, (int) $cashier->final_amount);
        $this->assertSame(-17, (int) $cashier->rounding_amount);
    }
}
