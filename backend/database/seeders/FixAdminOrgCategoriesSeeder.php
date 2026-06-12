<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FixAdminOrgCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $org = \App\Models\Organization::find(1);
        if ($org) {
            $categories = [
                ['name' => 'Food', 'slug' => 'food-admin', 'is_active' => true, 'sort_order' => 1],
                ['name' => 'Minuman', 'slug' => 'minuman-admin', 'is_active' => true, 'sort_order' => 2],
                ['name' => 'Dessert', 'slug' => 'dessert-admin', 'is_active' => true, 'sort_order' => 3],
            ];

            foreach ($categories as $cat) {
                \App\Models\Category::create([
                    'tenant_id' => $org->id,
                    'name' => $cat['name'],
                    'slug' => $cat['slug'],
                    'is_active' => $cat['is_active'],
                    'sort_order' => $cat['sort_order'],
                ]);
            }
        }
    }
}