<?php

namespace Database\Seeders;

use App\Models\DigitalProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DigitalProductSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'name' => 'POS / Kasir Digital',
                'category' => 'application',
                'type' => 'paid',
                'price' => 199000,
                'tagline' => 'Kelola transaksi, stok, dan laporan real-time.',
            ],
            [
                'name' => 'Landing Page Builder',
                'category' => 'application',
                'type' => 'free',
                'price' => 0,
                'tagline' => 'Buat landing page profesional tanpa coding.',
            ],
            [
                'name' => 'Aplikasi Member & Loyalitas',
                'category' => 'application',
                'type' => 'paid',
                'price' => 149000,
                'tagline' => 'Bangun relasi pelanggan dengan reward otomatis.',
            ],
            [
                'name' => 'Template Toko Online Pro',
                'category' => 'template',
                'type' => 'paid',
                'price' => 70000,
                'tagline' => 'Template toko online siap pakai dengan UI premium.',
            ],
            [
                'name' => 'Suvarna Gaya Luwear',
                'category' => 'template',
                'type' => 'paid',
                'price' => 100000,
                'tagline' => 'Template fashion store untuk brand premium.',
            ],
            [
                'name' => 'Kursus Digital Marketing UMKM',
                'category' => 'course',
                'type' => 'paid',
                'price' => 149000,
                'tagline' => 'Materi lengkap untuk growth UMKM di era digital.',
            ],
        ];

        foreach ($items as $index => $item) {
            $slug = Str::slug($item['name']);
            DigitalProduct::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    ...$item,
                    'slug' => $slug,
                    'currency' => 'IDR',
                    'is_published' => true,
                    'is_featured' => $index < 3,
                    'sort_order' => $index,
                    'total_purchases' => 0,
                    'total_downloads' => 0,
                ]
            );
        }
    }
}
