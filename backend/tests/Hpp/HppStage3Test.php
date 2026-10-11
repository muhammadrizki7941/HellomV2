<?php

namespace Tests\Hpp;

use App\Jobs\BackflushPosOrder;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Hpp\HppStockMovement;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Services\Hpp\StockLedger;

/** Tahap 3: varian & modifier, backflush stok bahan dari POS, peringatan margin. */
class HppStage3Test extends HppTestCase
{
    private function posHeaders(Organization $org, Outlet $outlet): array
    {
        [$h, $user] = $this->member($org);
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        Entitlement::query()->firstOrCreate(['organization_id' => $org->id, 'app_id' => $app->id], ['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);

        return $h + ['X-Outlet-Id' => (string) $outlet->id];
    }

    public function test_variants_add_their_own_cost(): void
    {
        ['org' => $org] = $this->makeOrganization('J1');
        [$h] = $this->member($org);
        $ids = $this->kopiSusu($h);
        $p = $this->withHeaders($h)->getJson(self::API . "/products/{$ids['product']}")->json('data.product');
        $items = collect($p['lines'])->map(fn ($l) => ['component_type' => $l['component_type'], 'component_id' => $l['component_id'], 'qty' => $l['qty']])->all();

        $p = $this->withHeaders($h)->putJson(self::API . "/products/{$ids['product']}", [
            'name' => $p['name'], 'mode' => 'recipe', 'yield_qty' => 1, 'yield_unit' => 'cup', 'selling_price' => 21000, 'items' => $items,
            'variants' => [
                ['name' => 'Extra shot', 'kind' => 'modifier', 'price_delta' => 5000, 'items' => [['component_type' => 'ingredient', 'component_id' => $ids['ingredients']['kopi'], 'qty' => 9]]],
                ['name' => 'Large', 'kind' => 'size', 'price_delta' => 3000, 'items' => [['component_type' => 'ingredient', 'component_id' => $ids['ingredients']['susu'], 'qty' => 50]]],
            ],
        ])->assertOk()->json('data.product');
        $variants = collect($p['variants'])->keyBy('name');
        $this->assertEquals(1620, $variants['Extra shot']['extra_cost']); // 9 g × Rp180
        $this->assertEquals(9660, $variants['Extra shot']['full']);
        $this->assertSame(26000, $variants['Extra shot']['price']);
        $this->assertEquals(1000, $variants['Large']['extra_cost']); // 50 ml × Rp20
        $this->assertCount(1, $p['variant_lines'][(string) $variants['Large']['id']]);

        // A POS option can only come from the linked POS menu item.
        $this->withHeaders($h)->putJson(self::API . "/products/{$ids['product']}", [
            'name' => $p['name'], 'mode' => 'recipe', 'yield_qty' => 1, 'yield_unit' => 'cup', 'items' => $items,
            'variants' => [['name' => 'X', 'kind' => 'modifier', 'pos_option_value_id' => 999999]],
        ])->assertStatus(422);
    }

    public function test_paid_pos_order_backflushes_ingredients_once(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('J2');
        $h = $this->posHeaders($org, $outlet);
        $ids = $this->kopiSusu($h);
        $pos = $this->makeProduct($outlet, 21000, ['name' => 'Es Kopi Susu Gula Aren']);
        $option = ProductOption::query()->create(['product_id' => $pos->id, 'name' => 'Tambahan', 'type' => 'multi', 'is_required' => false, 'is_active' => true]);
        $shot = ProductOptionValue::query()->create(['product_option_id' => $option->id, 'name' => 'Extra shot', 'price_delta' => 5000, 'is_active' => true]);

        $p = $this->withHeaders($h)->getJson(self::API . "/products/{$ids['product']}")->json('data.product');
        $this->withHeaders($h)->putJson(self::API . "/products/{$ids['product']}", [
            'name' => $p['name'], 'mode' => 'recipe', 'yield_qty' => 1, 'yield_unit' => 'cup', 'pos_product_id' => $pos->id,
            'items' => collect($p['lines'])->map(fn ($l) => ['component_type' => $l['component_type'], 'component_id' => $l['component_id'], 'qty' => $l['qty']])->all(),
            'variants' => [['name' => 'Extra shot', 'kind' => 'modifier', 'pos_option_value_id' => $shot->id,
                'items' => [['component_type' => 'ingredient', 'component_id' => $ids['ingredients']['kopi'], 'qty' => 9]]]],
        ])->assertOk();
        $this->assertEquals(['options' => [['id' => $option->id, 'name' => 'Tambahan', 'values' => [['id' => $shot->id, 'name' => 'Extra shot', 'price_delta' => 5000]]]]],
            $this->withHeaders($h)->getJson(self::API . "/pos-products/{$pos->id}/options")->assertOk()->json('data'));

        // Unpaid / cancelled orders take nothing.
        $api = '/api/v1/hellom/pos';
        $order = $this->withHeaders($h)->postJson("{$api}/orders", ['items' => [['product_id' => $pos->id, 'quantity' => 2, 'options' => [['option_id' => $option->id, 'value_id' => $shot->id]]]]])->assertCreated()->json('data.order');
        $this->assertSame(0, HppStockMovement::query()->where('ref_type', 'pos_order')->where('ref_id', $order['id'])->count());

        // Paid → 2 cups with extra shot: kopi 2×(18+9) g, susu 300 ml, cup 2, gula aren 2×10 g (via the syrup sub-recipe).
        $this->withHeaders($h)->postJson("{$api}/orders/{$order['id']}/payment", ['payment_method' => 'cash', 'payment_amount' => 60000])->assertOk();
        $moves = HppStockMovement::query()->where('ref_type', 'pos_order')->where('ref_id', $order['id'])->pluck('qty', 'ingredient_id')
            ->map(fn ($q) => (float) $q)->sortKeys()->all();
        $expected = [
            $ids['ingredients']['kopi'] => -54.0, $ids['ingredients']['susu'] => -300.0, $ids['ingredients']['aren'] => -20.0,
            $ids['ingredients']['es'] => -2.0, $ids['ingredients']['cup'] => -2.0,
        ];
        ksort($expected);
        $this->assertEquals($expected, $moves);
        $this->assertEquals(-54.0, app(StockLedger::class)->qty($ids['ingredients']['kopi'], (int) $outlet->id));

        // Running the job again changes nothing.
        BackflushPosOrder::dispatchSync((int) $order['id']);
        $this->assertSame(5, HppStockMovement::query()->where('ref_type', 'pos_order')->where('ref_id', $order['id'])->count());
        $this->assertEquals(-54.0, app(StockLedger::class)->qty($ids['ingredients']['kopi']));

        // Backflush can be switched off.
        $this->withHeaders($h)->putJson(self::API . '/settings', ['backflush_enabled' => false])->assertOk();
        $second = $this->withHeaders($h)->postJson("{$api}/orders", ['items' => [['product_id' => $pos->id, 'quantity' => 1]]])->assertCreated()->json('data.order');
        $this->withHeaders($h)->postJson("{$api}/orders/{$second['id']}/payment", ['payment_method' => 'cash', 'payment_amount' => 30000])->assertOk();
        $this->assertSame(0, HppStockMovement::query()->where('ref_type', 'pos_order')->where('ref_id', $second['id'])->count());
    }

    public function test_margin_alert_names_the_price_rise_and_suggests_a_price(): void
    {
        ['org' => $org] = $this->makeOrganization('J3');
        [$h] = $this->member($org);
        $this->withHeaders($h)->postJson(self::API . '/onboarding', ['business_type' => 'fnb', 'mode' => 'recipe', 'use_template' => false])->assertOk();
        $ids = $this->kopiSusu($h);
        $p = $this->withHeaders($h)->getJson(self::API . "/products/{$ids['product']}")->json('data.product');
        $this->withHeaders($h)->putJson(self::API . "/products/{$ids['product']}", [
            'name' => $p['name'], 'mode' => 'recipe', 'yield_qty' => 1, 'yield_unit' => 'cup', 'selling_price' => 14000,
            'items' => collect($p['lines'])->map(fn ($l) => ['component_type' => $l['component_type'], 'component_id' => $l['component_id'], 'qty' => $l['qty']])->all(),
        ])->assertOk();
        $this->assertSame([], $this->withHeaders($h)->getJson(self::API . '/alerts')->json('data.alerts')); // 41,9% ≥ target

        // Susu Rp20 → Rp28/ml (+40%): HPP 9.240, margin (14.000 − 9.240 − 98) ÷ 14.000 = 33,3% < target.
        $this->withHeaders($h)->postJson(self::API . '/purchases', ['purchased_at' => now()->toDateString(), 'items' => [['ingredient_id' => $ids['ingredients']['susu'], 'qty' => 1, 'total_price' => 28000]]])->assertCreated();
        $alerts = $this->withHeaders($h)->getJson(self::API . '/alerts')->assertOk()->json('data.alerts');
        $this->assertCount(1, $alerts);
        $this->assertSame('Susu UHT', $alerts[0]['cause']['name']);
        $this->assertEquals(40, $alerts[0]['cause']['change_pct']);
        $this->assertStringContainsString('Harga Susu UHT naik 40%: margin Es Kopi Susu Gula Aren turun ke', $alerts[0]['message']);
        $this->assertStringContainsString('Saran: naikkan ke Rp16.000.', $alerts[0]['message']); // 9.240 ÷ 0,593 = 15.582
        $this->assertCount(1, $this->withHeaders($h)->getJson(self::API . '/overview')->json('data.alerts'));
    }
}
