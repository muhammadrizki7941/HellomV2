<?php

namespace Tests\Hpp;

use App\Models\Hpp\HppProduct;
use App\Models\PosActivityLog;
use App\Services\Hpp\PriceAdvisor;
use App\Support\Pos\ActivityActions;

/** Tahap 1: master bahan, resep + kemasan + sub-resep, Mode Cepat, saran harga, tautan POS. */
class HppStage1Test extends HppTestCase
{
    public function test_units_yield_recipe_and_the_kopi_susu_example(): void
    {
        ['org' => $org] = $this->makeOrganization('H1');
        [$h] = $this->member($org);

        // 1 kg = Rp180.000 → Rp180/gram; chicken 1 kg → 800 g usable → Rp47,5 per usable gram.
        $kopi = $this->ingredient($h, 'Kopi', 'g', 'kg', 180000);
        $this->assertSame(180.0, $this->ingredientModel($kopi)->cost_per_unit);
        $ayam = $this->ingredient($h, 'Ayam', 'g', 'kg', 38000, ['yield_pct' => 80]);
        $this->assertSame(47.5, $this->ingredientModel($ayam)->effectiveCost());
        // Packs need the user to say what is inside.
        $this->withHeaders($h)->postJson(self::API . '/ingredients', ['name' => 'Cup', 'kind' => 'packaging', 'use_unit' => 'pcs', 'buy_unit' => 'pak', 'buy_price' => 50000])
            ->assertStatus(422)->assertJsonPath('errors.buy_factor.0', 'Isi berapa pcs dalam 1 pak.');

        $ids = $this->kopiSusu($h);
        $product = $this->withHeaders($h)->getJson(self::API . "/products/{$ids['product']}")->assertOk()->json('data.product');
        $this->assertEquals(6840, $product['cost']['material']);
        $this->assertEquals(1200, $product['cost']['packaging']);
        $this->assertEquals(8040, $product['cost']['direct']);
        $this->assertEquals(25, $product['lines'][2]['unit_cost']); // sirup Rp25/ml
        $this->assertSame(38.3, $product['food_cost_pct']); // 8.040 / 21.000
        $this->assertSame('yellow', $product['food_cost_status']);
        $this->assertSame(14000, $product['suggested_price']); // 8.040 ÷ 0,6 = 13.400 → ribuan

        // A sub-recipe cannot use a product that uses it.
        $this->withHeaders($h)->putJson(self::API . "/products/{$ids['syrup']}", [
            'name' => 'Sirup gula aren', 'mode' => 'recipe', 'is_subrecipe' => true, 'yield_qty' => 1000, 'yield_unit' => 'ml',
            'items' => [['component_type' => 'product', 'component_id' => $ids['syrup'], 'qty' => 1]],
        ])->assertStatus(422);
    }

    public function test_quick_mode_rounding_and_price_formula(): void
    {
        ['org' => $org] = $this->makeOrganization('H2');
        [$h] = $this->member($org);
        $quick = $this->withHeaders($h)->postJson(self::API . '/products', [
            'name' => 'Nasi kuning', 'mode' => 'quick', 'quick_batch_cost' => 200000, 'yield_qty' => 50, 'yield_unit' => 'porsi', 'selling_price' => 8000,
        ])->assertCreated()->json('data.product');
        $this->assertEquals(4000, $quick['cost']['full']);
        $this->assertEquals(50, $quick['margin_pct']);

        // The brief's numbers: Rp11.907 at 40% margin, QRIS 0,7% → Rp21.000; GoFood 20% → Rp30.000.
        $this->assertSame(21000, PriceAdvisor::suggest(11907, 40, 0.7));
        $this->assertSame(30000, PriceAdvisor::suggest(11907, 40, 20));
        $this->assertSame(20500, PriceAdvisor::suggest(11907, 40, 0.7, 0, '500'));
        $this->assertSame(20900, PriceAdvisor::suggest(11907, 40, 0.7, 0, 'psych'));
        $this->assertSame(19900, PriceAdvisor::round(19900, 'psych'));
        $this->assertNull(PriceAdvisor::suggest(10000, 80, 20));
    }

    public function test_onboarding_template_access_and_isolation(): void
    {
        ['org' => $org] = $this->makeOrganization('H3');
        ['org' => $other] = $this->makeOrganization('H4');
        [$h] = $this->member($org);
        [$cashier] = $this->member($org, 'cashier');
        [$stranger] = $this->member($other);

        $this->withHeaders($h)->getJson(self::API . '/overview')->assertOk()->assertJsonPath('data.settings.onboarded', false);
        $this->withHeaders($h)->postJson(self::API . '/onboarding', ['business_type' => 'fnb', 'mode' => 'quick'])->assertOk()->assertJsonPath('data.settings.mode', 'quick');
        $ingredients = $this->withHeaders($h)->getJson(self::API . '/ingredients')->assertOk()->json('data.ingredients');
        $this->assertGreaterThan(10, count($ingredients));
        $this->assertContains('Biji kopi', array_column($ingredients, 'name'));

        $this->withHeaders($cashier)->getJson(self::API . '/overview')->assertForbidden();
        $id = $ingredients[0]['id'];
        $this->withHeaders($stranger)->putJson(self::API . "/ingredients/{$id}", ['name' => 'x', 'kind' => 'ingredient', 'use_unit' => 'g', 'buy_unit' => 'kg', 'buy_price' => 1])->assertNotFound();

        // The dashboard shows the free app.
        $card = collect($this->withHeaders($h)->getJson('/api/v1/hellom/member/dashboard/cards')->assertOk()->json('data.cards'))->firstWhere('app.slug', 'hpp');
        $this->assertTrue($card['entitlement']['allowed']);
        $this->assertSame('/dashboard/apps/hpp', $card['access']['dashboard_path']);
    }

    public function test_pos_menu_import_link_and_apply_price(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('H5');
        [$h] = $this->member($org);
        $pos = $this->makeProduct($outlet, 18000, ['name' => 'Es Kopi Susu']);
        $this->makeProduct($outlet, 5000, ['name' => 'Es Teh']);

        $this->withHeaders($h)->postJson(self::API . '/products/import-pos')->assertOk()->assertJsonPath('data.created', 2);
        $this->withHeaders($h)->postJson(self::API . '/products/import-pos')->assertOk()->assertJsonPath('data.created', 0);
        $hpp = HppProduct::query()->where('organization_id', $org->id)->where('pos_product_id', $pos->id)->firstOrFail();

        // Price comes from POS; writing the suggested price updates POS and the audit log.
        $this->assertSame(18000, $this->withHeaders($h)->getJson(self::API . "/products/{$hpp->id}")->json('data.product.selling_price'));
        $this->withHeaders($h)->postJson(self::API . "/products/{$hpp->id}/apply-price", ['price' => 21000])->assertOk()->assertJsonPath('data.product.selling_price', 21000);
        $this->assertSame(21000, (int) $pos->fresh()->price);
        $log = PosActivityLog::query()->where('organization_id', $org->id)->where('action', ActivityActions::PRODUCT_UPDATED)->latest('id')->firstOrFail();
        $this->assertSame(['18000', '21000', 'Harga dari Kalkulator HPP'], [(string) $log->before_state['price'], (string) $log->after_state['price'], $log->reason]);

        // A POS item links to one HPP product only.
        $this->withHeaders($h)->postJson(self::API . '/products', ['name' => 'Duplikat', 'mode' => 'quick', 'quick_batch_cost' => 1000, 'yield_qty' => 1, 'yield_unit' => 'porsi', 'pos_product_id' => $pos->id])
            ->assertStatus(422)->assertJsonPath('errors.pos_product_id.0', 'Menu POS ini sudah ditautkan ke produk lain.');
    }
}
