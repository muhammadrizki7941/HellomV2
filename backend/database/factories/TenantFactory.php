<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'slug' => $this->faker->unique()->slug(2),
            'status' => 'active',
            'plan' => 'basic',
            'trial_started_at' => now(),
            'active_until' => now()->addDays(30),
            'subdomain' => $this->faker->unique()->domainWord(),
            'custom_domain' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'active_until' => now()->subDays(1),
        ]);
    }
}