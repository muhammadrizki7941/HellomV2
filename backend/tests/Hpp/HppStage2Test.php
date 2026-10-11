<?php

namespace Tests\Hpp;

use App\Models\Hpp\HppChannel;
use App\Models\Hpp\HppCostSnapshot;
use App\Models\Hpp\HppIngredient;
use App\Models\Hpp\HppSetting;

/** Tahap 2: overhead, harga per channel, BEP, update harga bahan dari pembelian (+ stok, snapshot). */
class HppStage2Test extends HppTestCase
{
    public function test_full_example_overhead_channels_and_break_even(): void
    {
        ['org' => $org] = $this->makeOrganization('H6');
        [$h] = $this->member($org);
        $this->withHeaders($h)->postJson(self::API . '/onboarding', ['business_type' => 'fnb', 'mode' => 'recipe', 'use_template' => false])->assertOk();
        $ids = $this->kopiSusu($h);

        // Rp5,8 jt overhead ÷ 1.500 cup per month = Rp3.867 per cup.
        $this->withHeaders($h)->postJson(self::API . '/overheads/template')->assertOk()->assertJsonPath('data.overhead_total', 5800000);
        $this->withHeaders($h)->putJson(self::API . '/settings', ['overhead_method' => 'per_unit', 'monthly_units' => 1500])->assertOk();

        $p = $this->withHeaders($h)->getJson(self::API . "/products/{$ids['product']}")->assertOk()->json('data.product');
        $this->assertEquals(8040, $p['cost']['direct']);
        $this->assertEqualsWithDelta(3866.67, $p['cost']['overhead'], 0.01);
        $this->assertEqualsWithDelta(11906.67, $p['cost']['full'], 0.01);
        $this->assertSame('QRIS', $p['main_channel']['name']);
        $this->assertSame(21000, $p['suggested_price']); // ÷ 0,593 → Rp20.079 → Rp21.000
        $channels = collect($p['channels'])->keyBy('name');
        $this->assertSame(30000, $channels['GoFood']['suggested_price']); // ÷ 0,40 → Rp29.768 → Rp30.000
        $this->assertNull($channels['GoFood']['price']); // ojol needs its own price
        $this->assertSame(21000, $channels['QRIS']['price']);
        // BEP: Rp5.800.000 ÷ (21.000 − 8.040 − 147) ≈ 453 cup/bulan, ±15 per hari.
        $this->assertSame(453, $p['bep']['units_per_month']);
        $this->assertSame(15.1, $p['bep']['units_per_day']);

        // Own GoFood price → its margin.
        $gofood = HppChannel::query()->where('organization_id', $org->id)->where('kind', 'gofood')->value('id');
        $p = $this->withHeaders($h)->putJson(self::API . "/products/{$ids['product']}", [
            'name' => 'Es Kopi Susu Gula Aren', 'mode' => 'recipe', 'yield_qty' => 1, 'yield_unit' => 'cup', 'selling_price' => 21000,
            'channel_prices' => [$gofood => 30000, 999999 => 1],
            'items' => collect($p['lines'])->map(fn ($l) => ['component_type' => $l['component_type'], 'component_id' => $l['component_id'], 'qty' => $l['qty']])->all(),
        ])->assertOk()->json('data.product');
        $go = collect($p['channels'])->firstWhere('name', 'GoFood');
        $this->assertSame(30000, $go['price']);
        $this->assertEquals(40.3, $go['margin_pct']); // (30.000 − 11.907 − 6.000) ÷ 30.000
        $this->assertEquals([(string) $gofood => 30000], $p['channel_prices']);

        $report = $this->withHeaders($h)->getJson(self::API . '/reports/products')->assertOk()->json('data');
        $this->assertSame(5800000, $report['overhead_total']);
        $this->assertSame('Es Kopi Susu Gula Aren', $report['most_profitable'][0]['name']);
    }

    public function test_purchases_update_prices_stock_and_recalculate(): void
    {
        ['org' => $org] = $this->makeOrganization('H7');
        [$h] = $this->member($org);
        $ids = $this->kopiSusu($h);
        $susu = $ids['ingredients']['susu']; // Rp20/ml

        // Last price: 2 liter for Rp46.000 → Rp23/ml (+15%).
        $res = $this->withHeaders($h)->postJson(self::API . '/purchases', [
            'purchased_at' => now()->toDateString(), 'supplier' => 'Toko Susu', 'items' => [['ingredient_id' => $susu, 'qty' => 2, 'total_price' => 46000]],
        ])->assertCreated()->json('data');
        $this->assertEquals(15, $res['changes'][0]['change_pct']);
        $this->assertSame(23.0, HppIngredient::query()->find($susu)->cost_per_unit);
        $this->assertSame(23000, HppIngredient::query()->find($susu)->last_buy_price);
        $this->assertEquals(2000, collect($this->withHeaders($h)->getJson(self::API . '/ingredients')->json('data.ingredients'))->firstWhere('id', $susu)['stock']);

        // HPP recalculated in the job (sync in tests): 8.040 + 150 ml × Rp3 = 8.490; snapshot stored.
        $this->assertEquals(8490, $this->withHeaders($h)->getJson(self::API . "/products/{$ids['product']}")->json('data.product.cost.full'));
        $snapshot = HppCostSnapshot::query()->where('product_id', $ids['product'])->whereDate('snapshot_date', now())->firstOrFail();
        $this->assertEquals(8490, $snapshot->cost_full);

        // Weighted average: 2.000 ml @23 + 1.000 ml @26 → Rp24/ml.
        $this->withHeaders($h)->putJson(self::API . '/settings', ['price_method' => 'average'])->assertOk();
        $this->withHeaders($h)->postJson(self::API . '/purchases', [
            'purchased_at' => now()->toDateString(), 'items' => [['ingredient_id' => $susu, 'qty' => 1, 'total_price' => 26000]],
        ])->assertCreated();
        $this->assertSame(24.0, HppIngredient::query()->find($susu)->cost_per_unit);

        $list = $this->withHeaders($h)->getJson(self::API . '/purchases')->assertOk()->json('data.purchases');
        $this->assertCount(2, $list);
        $this->assertEquals(15, $list[1]['items'][0]['change_pct']);

        // Future date and another shop's ingredient are refused.
        $this->withHeaders($h)->postJson(self::API . '/purchases', ['purchased_at' => now()->addDay()->toDateString(), 'items' => [['ingredient_id' => $susu, 'qty' => 1, 'total_price' => 1]]])->assertStatus(422);
        ['org' => $other] = $this->makeOrganization('H8');
        [$oh] = $this->member($other);
        $this->withHeaders($oh)->postJson(self::API . '/purchases', ['purchased_at' => now()->toDateString(), 'items' => [['ingredient_id' => $susu, 'qty' => 1, 'total_price' => 1]]])
            ->assertStatus(422)->assertJsonPath('errors', ['items.0.ingredient_id' => ['Bahan tidak ditemukan.']]);
    }

    public function test_overhead_by_revenue_and_by_labour_hour(): void
    {
        ['org' => $org] = $this->makeOrganization('H9');
        [$h] = $this->member($org);
        $this->withHeaders($h)->postJson(self::API . '/overheads', ['name' => 'Sewa', 'category' => 'rent', 'amount' => 3000000])->assertCreated();
        $id = (int) $this->withHeaders($h)->postJson(self::API . '/products', [
            'name' => 'Potong rambut', 'mode' => 'quick', 'quick_batch_cost' => 5000, 'yield_qty' => 1, 'yield_unit' => 'layanan', 'labor_minutes' => 30, 'selling_price' => 50000,
        ])->assertCreated()->json('data.product.id');

        // 10% of revenue (Rp3 jt of Rp30 jt omzet) → Rp5.000 at Rp50.000.
        $this->withHeaders($h)->putJson(self::API . '/settings', ['overhead_method' => 'revenue_pct', 'monthly_revenue' => 30000000])->assertOk();
        $this->assertEquals(5000, $this->withHeaders($h)->getJson(self::API . "/products/{$id}")->json('data.product.cost.overhead'));

        // Per labour hour: Rp3 jt ÷ 150 jam = Rp20.000/jam × 30 menit = Rp10.000; tenaga kerja Rp40.000/jam × 0,5.
        $this->withHeaders($h)->putJson(self::API . '/settings', ['overhead_method' => 'labor_hour', 'monthly_labor_hours' => 150, 'labor_rate_per_hour' => 40000])->assertOk();
        $p = $this->withHeaders($h)->getJson(self::API . "/products/{$id}")->json('data.product');
        $this->assertEquals(10000, $p['cost']['overhead']);
        $this->assertEquals(20000, $p['cost']['labor']);
        $this->assertEquals(35000, $p['cost']['full']);
        $this->assertSame('labor_hour', HppSetting::for($org->id)->overhead_method);
    }
}
