<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            // Free plan
            [
                'slug' => 'free',
                'name' => 'Free',
                'type' => 'free',
                'price' => 0,
                'description' => 'Gratis untuk mencoba fitur dasar',
                'features' => [
                    'max_products' => 50,
                    'max_orders_per_day' => 20,
                    'landing_builder' => true,
                ],
                'billing_cycles' => [],
                'duration_days' => null,
                'is_visible' => true,
                'is_active' => true,
                'sort_order' => 0,
            ],
            // POS Starter Monthly
            [
                'slug' => 'pos_starter_monthly',
                'name' => 'POS Starter - Bulanan',
                'type' => 'subscription',
                'price' => 150000,
                'description' => 'Paket starter dengan fitur POS lengkap',
                'features' => [
                    'max_products' => 500,
                    'max_orders_per_day' => 100,
                    'table_management' => true,
                    'reservation' => true,
                    'analytics' => true,
                ],
                'billing_cycles' => ['monthly'],
                'duration_days' => 30,
                'is_visible' => true,
                'is_active' => true,
                'sort_order' => 10,
            ],
            // POS Starter Yearly (One-time)
            [
                'slug' => 'pos_starter_yearly',
                'name' => 'POS Starter - Tahunan',
                'type' => 'one_time',
                'price' => 1500000,
                'description' => 'Bayar sekali, berlaku 1 tahun',
                'features' => [
                    'max_products' => 500,
                    'max_orders_per_day' => 100,
                    'table_management' => true,
                    'reservation' => true,
                    'analytics' => true,
                ],
                'billing_cycles' => ['yearly'],
                'duration_days' => 365,
                'is_visible' => true,
                'is_active' => true,
                'sort_order' => 11,
            ],
            // POS Pro Monthly
            [
                'slug' => 'pos_pro_monthly',
                'name' => 'POS Pro - Bulanan',
                'type' => 'subscription',
                'price' => 300000,
                'description' => 'Paket profesional dengan semua fitur',
                'features' => [
                    'max_products' => 2000,
                    'max_orders_per_day' => 500,
                    'table_management' => true,
                    'reservation' => true,
                    'analytics' => true,
                    'priority_support' => true,
                    'custom_branding' => true,
                ],
                'billing_cycles' => ['monthly'],
                'duration_days' => 30,
                'is_visible' => true,
                'is_active' => true,
                'sort_order' => 20,
            ],
            // POS Pro Yearly (One-time)
            [
                'slug' => 'pos_pro_yearly',
                'name' => 'POS Pro - Tahunan',
                'type' => 'one_time',
                'price' => 3000000,
                'description' => 'Bayar sekali, berlaku 1 tahun - hemat 2 bulan',
                'features' => [
                    'max_products' => 2000,
                    'max_orders_per_day' => 500,
                    'table_management' => true,
                    'reservation' => true,
                    'analytics' => true,
                    'priority_support' => true,
                    'custom_branding' => true,
                ],
                'billing_cycles' => ['yearly'],
                'duration_days' => 365,
                'is_visible' => true,
                'is_active' => true,
                'sort_order' => 21,
            ],
            // POS Lifetime
            [
                'slug' => 'pos_lifetime',
                'name' => 'POS Lifetime',
                'type' => 'lifetime',
                'price' => 5000000,
                'description' => 'Bayar sekali, berlaku selamanya',
                'features' => [
                    'max_products' => -1, // unlimited
                    'max_orders_per_day' => -1, // unlimited
                    'table_management' => true,
                    'reservation' => true,
                    'analytics' => true,
                    'priority_support' => true,
                    'custom_branding' => true,
                    'lifetime_updates' => true,
                ],
                'billing_cycles' => [],
                'duration_days' => null, // lifetime
                'is_visible' => true,
                'is_active' => true,
                'sort_order' => 30,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }

        $this->command->info('Plan seeder completed.');
        $this->command->info('Created ' . count($plans) . ' plans.');
    }
}