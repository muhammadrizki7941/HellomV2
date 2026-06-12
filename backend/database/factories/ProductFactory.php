<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1, // Default to first tenant
            'name' => $this->faker->words(2, true),
            'slug' => $this->faker->slug(),
            'description' => $this->faker->optional()->sentence(),
            'price' => $this->faker->numberBetween(10000, 50000),
            'image_url' => $this->faker->optional()->imageUrl(),
            'is_available' => $this->faker->boolean(80), // 80% chance of being available
            'category_id' => Category::factory(),
            'sort_order' => $this->faker->numberBetween(1, 100),
        ];
    }

    public function available(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_available' => true,
        ]);
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_available' => false,
        ]);
    }
}