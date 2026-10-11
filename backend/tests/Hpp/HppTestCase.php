<?php

namespace Tests\Hpp;

use App\Models\ApiToken;
use App\Models\Hpp\HppIngredient;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Pos\PosTestCase;

/** Kalkulator HPP tests (MySQL hellom_pos_test via phpunit.pos.xml). */
abstract class HppTestCase extends PosTestCase
{
    use DatabaseTransactions;

    protected const API = '/api/v1/hellom/hpp';

    /** @return array{0: array<string, string>, 1: User} */
    protected function member(Organization $org, string $pivotRole = 'owner'): array
    {
        $user = User::query()->create([
            'name' => ucfirst($pivotRole) . ' ' . Str::random(4), 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('secret-password'),
            'role' => $pivotRole === 'cashier' ? 'cashier' : 'admin', 'current_organization_id' => $org->id,
        ]);
        $org->users()->attach($user->id, ['role' => $pivotRole]);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 'test', 'token_hash' => hash('sha256', $plain)]);

        return [['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'], $user];
    }

    /** Ingredient through the API (buy unit + price → cost per use unit). */
    protected function ingredient(array $headers, string $name, string $useUnit, string $buyUnit, int $buyPrice, array $extra = []): int
    {
        return (int) $this->withHeaders($headers)->postJson(self::API . '/ingredients', array_merge([
            'name' => $name, 'kind' => 'ingredient', 'use_unit' => $useUnit, 'buy_unit' => $buyUnit, 'buy_price' => $buyPrice,
        ], $extra))->assertCreated()->json('data.ingredient.id');
    }

    protected function ingredientModel(int $id): HppIngredient
    {
        return HppIngredient::query()->findOrFail($id);
    }

    /**
     * The "Es Kopi Susu Gula Aren" example: HPP bahan Rp8.040 (kopi 3.240, susu 3.000, sirup
     * sub-resep 500, es 100, cup set 1.200).
     *
     * @return array{product: int, syrup: int, ingredients: array<string, int>}
     */
    protected function kopiSusu(array $h): array
    {
        $ing = [
            'kopi' => $this->ingredient($h, 'Biji kopi', 'g', 'kg', 180000),
            'susu' => $this->ingredient($h, 'Susu UHT', 'ml', 'liter', 20000),
            'aren' => $this->ingredient($h, 'Gula aren', 'g', 'kg', 50000),
            'es' => $this->ingredient($h, 'Es batu', 'porsi', 'kantong', 10000, ['buy_factor' => 100]),
            'cup' => $this->ingredient($h, 'Cup + tutup + sedotan', 'pcs', 'pak', 60000, ['buy_factor' => 50, 'kind' => 'packaging']),
        ];
        $syrup = (int) $this->withHeaders($h)->postJson(self::API . '/products', [
            'name' => 'Sirup gula aren', 'mode' => 'recipe', 'is_subrecipe' => true, 'yield_qty' => 1000, 'yield_unit' => 'ml',
            'items' => [['component_type' => 'ingredient', 'component_id' => $ing['aren'], 'qty' => 500]],
        ])->assertCreated()->json('data.product.id');
        $product = (int) $this->withHeaders($h)->postJson(self::API . '/products', [
            'name' => 'Es Kopi Susu Gula Aren', 'mode' => 'recipe', 'yield_qty' => 1, 'yield_unit' => 'cup', 'selling_price' => 21000,
            'items' => [
                ['component_type' => 'ingredient', 'component_id' => $ing['kopi'], 'qty' => 18],
                ['component_type' => 'ingredient', 'component_id' => $ing['susu'], 'qty' => 150],
                ['component_type' => 'product', 'component_id' => $syrup, 'qty' => 20],
                ['component_type' => 'ingredient', 'component_id' => $ing['es'], 'qty' => 1],
                ['component_type' => 'ingredient', 'component_id' => $ing['cup'], 'qty' => 1],
            ],
        ])->assertCreated()->json('data.product.id');

        return ['product' => $product, 'syrup' => $syrup, 'ingredients' => $ing];
    }
}
