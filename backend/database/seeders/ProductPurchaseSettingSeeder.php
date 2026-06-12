<?php

namespace Database\Seeders;

use App\Models\ProductPurchaseSetting;
use Illuminate\Database\Seeder;

class ProductPurchaseSettingSeeder extends Seeder
{
    public function run(): void
    {
        // This seeder creates default purchase settings for each new organization
        // It's called programmatically when a new organization is created
        
        $this->command->info('ProductPurchaseSetting seeder ready.');
        $this->command->info('Use ProductPurchaseSetting::createDefaultsForOrganization($organizationId) to create defaults.');
    }

    public static function createDefaultsForOrganization(int $organizationId): void
    {
        $defaults = [
            [
                'service_type' => ProductPurchaseSetting::SERVICE_DINE_IN,
                'name' => 'Dine In',
                'description' => 'Dine in',
                'enabled' => true,
                'order_timing' => ProductPurchaseSetting::TIMING_IMMEDIATE,
                'lead_time_minutes' => 0,
                'available_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
                'require_payment_first' => false,
                'require_table' => true,
                'require_reservation' => false,
                'min_order_amount' => 0,
                'is_default' => true,
                'sort_order' => 1,
            ],
            [
                'service_type' => ProductPurchaseSetting::SERVICE_TAKE_AWAY,
                'name' => 'Take Away',
                'description' => 'Bawa pulang',
                'enabled' => true,
                'order_timing' => ProductPurchaseSetting::TIMING_IMMEDIATE,
                'lead_time_minutes' => 15,
                'available_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
                'require_payment_first' => true,
                'require_table' => false,
                'require_reservation' => false,
                'min_order_amount' => 0,
                'is_default' => false,
                'sort_order' => 2,
            ],
            [
                'service_type' => ProductPurchaseSetting::SERVICE_DELIVERY,
                'name' => 'Delivery',
                'description' => 'Diantar ke alamat',
                'enabled' => false,
                'order_timing' => ProductPurchaseSetting::TIMING_SCHEDULED,
                'lead_time_minutes' => 45,
                'available_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
                'require_payment_first' => true,
                'require_table' => false,
                'require_reservation' => false,
                'min_order_amount' => 25000,
                'is_default' => false,
                'sort_order' => 3,
            ],
            [
                'service_type' => ProductPurchaseSetting::SERVICE_PRE_ORDER,
                'name' => 'Pre Order',
                'description' => 'Pesan terlebih dahulu',
                'enabled' => false,
                'order_timing' => ProductPurchaseSetting::TIMING_SCHEDULED,
                'lead_time_minutes' => 120,
                'available_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
                'require_payment_first' => true,
                'require_table' => false,
                'require_reservation' => false,
                'min_order_amount' => 50000,
                'is_default' => false,
                'sort_order' => 4,
            ],
        ];

        foreach ($defaults as $default) {
            ProductPurchaseSetting::query()->updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'service_type' => $default['service_type'],
                ],
                array_merge($default, [
                    'organization_id' => $organizationId,
                ])
            );
        }
    }
}