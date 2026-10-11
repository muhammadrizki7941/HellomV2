<?php

namespace Tests\Hpp;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Hpp\HppIngredient;
use App\Models\Organization;
use App\Models\Plan;
use App\Services\Hpp\FifoCosting;
use Illuminate\Support\Carbon;

/** Tahap 4 (Pro, berbayar): akses, opname & selisih, simulasi, harga per outlet, FIFO, ekspor. */
class HppStage4Test extends HppTestCase
{
    private function entitle(Organization $org, string $appSlug, ?string $planSlug = null): void
    {
        $app = AppCatalog::query()->firstOrCreate(['slug' => $appSlug], ['name' => $appSlug, 'is_active' => true]);
        Entitlement::query()->updateOrCreate(['organization_id' => $org->id, 'app_id' => $app->id], [
            'plan_id' => $planSlug ? Plan::query()->where('slug', $planSlug)->value('id') : null,
            'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
    }

    public function test_pro_is_paid_free_with_pos_or_its_own_plan(): void
    {
        ['org' => $free] = $this->makeOrganization('K1');
        [$h] = $this->member($free);
        $this->withHeaders($h)->getJson(self::API . '/pro/variance')->assertForbidden()->assertJsonPath('error.code', 'HPP_PRO_REQUIRED')
            ->assertJsonPath('data.plans.0.slug', 'hpp_pro_monthly')->assertJsonPath('data.plans.0.price', 29000);
        $this->withHeaders($h)->putJson(self::API . '/settings', ['mode' => 'pro'])->assertForbidden();
        $this->withHeaders($h)->putJson(self::API . '/settings', ['price_method' => 'fifo'])->assertForbidden();
        $this->withHeaders($h)->getJson(self::API . '/overview')->assertOk()->assertJsonPath('data.pro.active', false);

        // POS Kasir subscribers get Pro for free.
        ['org' => $pos] = $this->makeOrganization('K2');
        [$ph] = $this->member($pos);
        $this->entitle($pos, 'pos');
        $this->withHeaders($ph)->getJson(self::API . '/overview')->assertJsonPath('data.pro.active', true)->assertJsonPath('data.pro.source', 'pos');
        $this->withHeaders($ph)->putJson(self::API . '/settings', ['mode' => 'pro', 'price_method' => 'fifo'])->assertOk();

        // Or the separate Kalkulator HPP Pro plan.
        $this->entitle($free, 'hpp', 'hpp_pro_monthly');
        $this->withHeaders($h)->getJson(self::API . '/overview')->assertJsonPath('data.pro.source', 'hpp');
        $this->withHeaders($h)->getJson(self::API . '/pro/variance')->assertOk();

        // Checkout offers the Pro plans under the "hpp" app (Admin › Paket edits their price).
        $matrix = collect($this->withHeaders($h)->getJson('/api/v1/hellom/pricing/matrix')->assertOk()->json('data.items'))->firstWhere('app.slug', 'hpp');
        $this->assertSame(['hpp_pro_monthly', 'hpp_pro_yearly'], array_column($matrix['plans'], 'slug'));
    }

    public function test_stock_count_variance_flags_usage_above_the_recipe(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('K3');
        [$h] = $this->member($org);
        $this->entitle($org, 'pos');
        $h += ['X-Outlet-Id' => (string) $outlet->id];
        $ids = $this->kopiSusu($h);
        $kopi = $ids['ingredients']['kopi'];
        $pos = $this->makeProduct($outlet, 21000, ['name' => 'Es Kopi']);
        $p = $this->withHeaders($h)->getJson(self::API . "/products/{$ids['product']}")->json('data.product');
        $this->withHeaders($h)->putJson(self::API . "/products/{$ids['product']}", [
            'name' => $p['name'], 'mode' => 'recipe', 'yield_qty' => 1, 'yield_unit' => 'cup', 'pos_product_id' => $pos->id,
            'items' => collect($p['lines'])->map(fn ($l) => ['component_type' => $l['component_type'], 'component_id' => $l['component_id'], 'qty' => $l['qty']])->all(),
        ])->assertOk();

        Carbon::setTestNow(now()->subHours(3));
        $this->withHeaders($h)->postJson(self::API . '/pro/stock-counts', ['outlet_id' => $outlet->id, 'items' => [['ingredient_id' => $kopi, 'counted_qty' => 1000]]])->assertCreated();
        Carbon::setTestNow(now()->addHour());
        $this->withHeaders($h)->postJson(self::API . '/purchases', ['purchased_at' => now()->toDateString(), 'outlet_id' => $outlet->id, 'items' => [['ingredient_id' => $kopi, 'qty' => 1, 'total_price' => 180000]]])->assertCreated();
        $order = $this->withHeaders($h)->postJson('/api/v1/hellom/pos/orders', ['items' => [['product_id' => $pos->id, 'quantity' => 10]]])->assertCreated()->json('data.order');
        $this->withHeaders($h)->postJson("/api/v1/hellom/pos/orders/{$order['id']}/payment", ['payment_method' => 'cash', 'payment_amount' => 300000])->assertOk();
        Carbon::setTestNow(now()->addHour());
        // Recipe says 180 g were used; the count says 300 g went: 120 g (Rp21.600) unexplained.
        $this->withHeaders($h)->postJson(self::API . '/pro/stock-counts', ['outlet_id' => $outlet->id, 'items' => [['ingredient_id' => $kopi, 'counted_qty' => 1700]]])->assertCreated();
        Carbon::setTestNow();

        $report = $this->withHeaders($h)->getJson(self::API . '/pro/variance?outlet_id=' . $outlet->id)->assertOk()->json('data');
        $row = collect($report['rows'])->firstWhere('ingredient_id', $kopi);
        $this->assertEquals([1000, 1000, 1700, 180, 300, 120, 66.7, 21600], [$row['opening'], $row['purchased'], $row['closing'], $row['theoretical'], $row['actual'], $row['variance'], $row['variance_pct'], $row['value']]);
        $this->assertTrue($row['flag']);

        $csv = $this->withHeaders($h)->get(self::API . '/pro/export?type=variance&outlet_id=' . $outlet->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('Pemakaian teoritis', $csv);
        $this->assertStringContainsString('"Biji kopi";g;1000;1000;1700;180;300;120;66,7;21600', $csv);
    }

    public function test_simulation_outlet_prices_fifo_and_export(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('K4');
        [$h] = $this->member($org);
        $this->entitle($org, 'pos');
        $ids = $this->kopiSusu($h);

        // Susu +20% → +Rp600 per cup (150 ml × Rp4).
        $sim = collect($this->withHeaders($h)->postJson(self::API . '/pro/simulate', ['ingredients' => [['id' => $ids['ingredients']['susu'], 'pct' => 20]]])->assertOk()->json('data.products'))->firstWhere('id', $ids['product']);
        $this->assertEquals(600, round($sim['cost_sim'] - $sim['cost_now']));
        $this->assertLessThan($sim['margin_now'], $sim['margin_sim']);

        // This outlet buys coffee dearer: Rp200/g → +Rp360 per cup there only.
        $this->withHeaders($h)->putJson(self::API . '/pro/outlet-prices', ['outlet_id' => $outlet->id, 'ingredient_id' => $ids['ingredients']['kopi'], 'cost_per_unit' => 200])->assertOk();
        $this->assertEquals(8040, $this->withHeaders($h)->getJson(self::API . "/products/{$ids['product']}")->json('data.product.cost.direct'));
        $this->assertEquals(8400, $this->withHeaders($h)->getJson(self::API . "/products/{$ids['product']}?outlet_id={$outlet->id}")->json('data.product.cost.direct'));

        // FIFO: the oldest lot sets the cost until it is used up.
        $this->withHeaders($h)->putJson(self::API . '/settings', ['price_method' => 'fifo'])->assertOk();
        $susu = $ids['ingredients']['susu'];
        $this->withHeaders($h)->postJson(self::API . '/purchases', ['purchased_at' => now()->subDay()->toDateString(), 'items' => [['ingredient_id' => $susu, 'qty' => 1, 'total_price' => 20000]]])->assertCreated();
        $this->withHeaders($h)->postJson(self::API . '/purchases', ['purchased_at' => now()->toDateString(), 'items' => [['ingredient_id' => $susu, 'qty' => 1, 'total_price' => 26000]]])->assertCreated();
        $this->assertSame(20.0, HppIngredient::query()->find($susu)->cost_per_unit);
        $fifo = app(FifoCosting::class);
        $this->assertEqualsWithDelta(1000 * 20 + 200 * 26, $fifo->consume($susu, 1200), 0.01);
        $this->assertSame(26.0, $fifo->currentCost($susu));

        $csv = $this->withHeaders($h)->get(self::API . '/pro/export?type=products')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFProduk;\"HPP bahan\";Kemasan", $csv);
        $this->assertStringContainsString('Es Kopi Susu Gula Aren', $csv);
    }
}
