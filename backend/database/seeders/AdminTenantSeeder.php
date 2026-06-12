<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\BrandSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\DiningTable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminTenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Admin User
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@resto.com'],
            [
                'name' => 'Admin Resto',
                'email' => 'admin@resto.com',
                'phone' => '+6281234567890',
                'password' => Hash::make('admin123'),
                'role' => 'admin',
                'points_balance' => 0,
            ]
        );

        // Create Cashier User
        $cashier = User::query()->updateOrCreate(
            ['email' => 'kasir@resto.com'],
            [
                'name' => 'Kasir Resto',
                'email' => 'kasir@resto.com',
                'phone' => '+6281234567891',
                'password' => Hash::make('kasir123'),
                'role' => 'cashier',
                'points_balance' => 0,
            ]
        );

        // Create Brand Settings
        $brand = BrandSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'business_name' => 'Resto Saya',
                'tagline' => 'Selamat Datang',
                'about' => 'Resto saya adalah restoran self-order modern',
                'phone' => '+6281234567890',
                'whatsapp' => '+6281234567890',
                'address' => 'Jl. Contoh No. 123, Kota Anda',
                'instagram' => '@restosaya',
                'primary_color' => '#0f172a',
                'secondary_color' => '#334155',
                'accent_color' => '#10b981',
                'background_color' => '#f8fafc',
                'button_radius' => 18,
                'font_family' => 'system-ui',
            ]
        );

        // Create Categories
        $categories = [
            ['name' => 'Food', 'slug' => 'food', 'is_active' => true, 'sort_order' => 1],
            ['name' => 'Minuman', 'slug' => 'minuman', 'is_active' => true, 'sort_order' => 2],
            ['name' => 'Snack', 'slug' => 'snack', 'is_active' => true, 'sort_order' => 3],
        ];

        $createdCategories = [];
        foreach ($categories as $category) {
            $createdCategories[] = Category::query()->updateOrCreate(
                ['name' => $category['name']],
                $category
            );
        }

        // Create Products
        $products = [
            // Food
            ['name' => 'Nasi Goreng', 'slug' => 'nasi-goreng', 'price' => 25000, 'is_available' => true, 'category_id' => $createdCategories[0]->id],
            ['name' => 'Mie Goreng', 'slug' => 'mie-goreng', 'price' => 22000, 'is_available' => true, 'category_id' => $createdCategories[0]->id],
            ['name' => 'Ayam Goreng', 'slug' => 'ayam-goreng', 'price' => 20000, 'is_available' => true, 'category_id' => $createdCategories[0]->id],
            ['name' => 'Sate Ayam', 'slug' => 'sate-ayam', 'price' => 30000, 'is_available' => true, 'category_id' => $createdCategories[0]->id],
            ['name' => 'Bakso', 'slug' => 'bakso', 'price' => 18000, 'is_available' => true, 'category_id' => $createdCategories[0]->id],
            
            // Minuman
            ['name' => 'Es Teh', 'slug' => 'es-teh', 'price' => 5000, 'is_available' => true, 'category_id' => $createdCategories[1]->id],
            ['name' => 'Kopi Hitam', 'slug' => 'kopi-hitam', 'price' => 8000, 'is_available' => true, 'category_id' => $createdCategories[1]->id],
            ['name' => 'Jus Alpukat', 'slug' => 'jus-alpukat', 'price' => 15000, 'is_available' => true, 'category_id' => $createdCategories[1]->id],
            ['name' => 'Es Jeruk', 'slug' => 'es-jeruk', 'price' => 7000, 'is_available' => true, 'category_id' => $createdCategories[1]->id],
            ['name' => 'Teh Hangat', 'slug' => 'teh-hangat', 'price' => 4000, 'is_available' => true, 'category_id' => $createdCategories[1]->id],
            
            // Snack
            ['name' => 'Kentang Goreng', 'slug' => 'kentang-goreng', 'price' => 12000, 'is_available' => true, 'category_id' => $createdCategories[2]->id],
            ['name' => 'Cireng', 'slug' => 'cireng', 'price' => 10000, 'is_available' => true, 'category_id' => $createdCategories[2]->id],
            ['name' => 'Pisang Goreng', 'slug' => 'pisang-goreng', 'price' => 8000, 'is_available' => true, 'category_id' => $createdCategories[2]->id],
        ];

        foreach ($products as $product) {
            Product::query()->updateOrCreate(
                ['slug' => $product['slug']],
                $product
            );
        }

         // Create Dining Tables
         $tables = [
             ['name' => 'Meja 1', 'code' => 'T1', 'public_id' => 'table-1', 'is_active' => true, 'tenant_id' => 'alpha'],
             ['name' => 'Meja 2', 'code' => 'T2', 'public_id' => 'table-2', 'is_active' => true, 'tenant_id' => 'alpha'],
             ['name' => 'Meja 3', 'code' => 'T3', 'public_id' => 'table-3', 'is_active' => true, 'tenant_id' => 'alpha'],
             ['name' => 'Meja 4', 'code' => 'T4', 'public_id' => 'table-4', 'is_active' => true, 'tenant_id' => 'alpha'],
             ['name' => 'Meja 5', 'code' => 'T5', 'public_id' => 'table-5', 'is_active' => true, 'tenant_id' => 'alpha'],
         ];

         foreach ($tables as $table) {
             DiningTable::query()->updateOrCreate(
                 ['code' => $table['code']],
                 $table
             );
         }

        $this->command->info('Admin Tenant Seeder completed!');
        $this->command->info('Admin Login: admin@resto.com / admin123');
        $this->command->info('Cashier Login: kasir@resto.com / kasir123');
    }
}
