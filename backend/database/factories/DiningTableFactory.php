<?php

namespace Database\Factories;

use App\Models\DiningTable;
use Illuminate\Database\Eloquent\Factories\Factory;

class DiningTableFactory extends Factory
{
    protected $model = DiningTable::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1, // Default to first tenant
            'public_id' => $this->faker->unique()->regexify('[a-z0-9]{12}'),
            'code' => $this->faker->unique()->regexify('T[0-9]{2}'),
            'name' => 'Table ' . $this->faker->numberBetween(1, 50),
            'is_active' => $this->faker->boolean(85), // 85% chance of being active
        ];
    }

    public function available(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_available' => true,
        ]);
    }

    public function occupied(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_available' => false,
        ]);
    }
}