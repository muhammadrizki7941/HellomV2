<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\DiningTable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1, // Default to first tenant
            'order_number' => 'ORD-' . $this->faker->unique()->numberBetween(1000, 9999),
            'dining_table_id' => 1, // Will be overridden in seeder
            'table_label' => 'Table ' . $this->faker->numberBetween(1, 20),
            'user_id' => null, // Optional for self-order
            'customer_name' => $this->faker->name(),
            'service_type' => $this->faker->randomElement(['dine_in', 'takeaway']),
            'order_source' => $this->faker->randomElement(['web', 'mobile', 'pos']),
            'status' => $this->faker->randomElement([
                Order::STATUS_NEW,
                Order::STATUS_ACCEPTED,
                Order::STATUS_PREPARING,
                Order::STATUS_COMPLETED,
                Order::STATUS_CANCELLED,
            ]),
            'total_amount' => $this->faker->numberBetween(25000, 150000),
            'discount_amount' => $this->faker->numberBetween(0, 5000),
            'redeemed_points' => $this->faker->numberBetween(0, 100),
            'payment_method' => $this->faker->randomElement(['qris', 'cash', 'card']),
            'payment_status' => $this->faker->randomElement(['unpaid', 'paid', 'refunded']),
            'payment_ref' => $this->faker->optional()->uuid(),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Order::STATUS_COMPLETED,
            'payment_status' => 'paid',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Order::STATUS_NEW,
            'payment_status' => 'unpaid',
        ]);
    }
}