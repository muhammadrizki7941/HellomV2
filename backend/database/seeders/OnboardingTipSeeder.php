<?php

namespace Database\Seeders;

use App\Models\OnboardingTip;
use Illuminate\Database\Seeder;

class OnboardingTipSeeder extends Seeder
{
    public function run(): void
    {
        $tips = [
            [
                'title' => 'Lengkapi Profil Bisnis Kamu',
                'body' => 'Tambahkan logo, nama bisnis, dan deskripsi agar terlihat profesional.',
                'action_url' => '/dashboard/settings',
                'action_text' => 'Lengkapi Sekarang',
                'icon' => 'user',
                'sort_order' => 1,
            ],
            [
                'title' => 'Aktifkan Aplikasi Pertama',
                'body' => 'Pilih aplikasi yang sesuai kebutuhan bisnis dan aktifkan sekarang.',
                'action_url' => '/dashboard/products',
                'action_text' => 'Lihat Produk',
                'icon' => 'grid',
                'sort_order' => 2,
            ],
            [
                'title' => 'Pelajari Cara Instalasi',
                'body' => 'Setiap produk punya panduan instalasi lengkap. Baca sebelum mulai.',
                'action_url' => '/dashboard/products',
                'action_text' => 'Buka Panduan',
                'icon' => 'book',
                'sort_order' => 3,
            ],
            [
                'title' => 'Hubungkan Domain Kamu',
                'body' => 'Untuk Landing Page Builder, hubungkan domain kamu agar bisa diakses publik.',
                'action_url' => '/dashboard/landing-builder',
                'action_text' => 'Hubungkan Domain',
                'icon' => 'link',
                'sort_order' => 4,
            ],
            [
                'title' => 'Undang Tim Kamu',
                'body' => 'Hellom mendukung multi-user. Undang anggota tim untuk kolaborasi.',
                'action_url' => '/dashboard/settings/team',
                'action_text' => 'Undang Tim',
                'icon' => 'users',
                'sort_order' => 5,
            ],
        ];

        foreach ($tips as $tip) {
            OnboardingTip::query()->updateOrCreate(
                ['title' => $tip['title']],
                [
                    ...$tip,
                    'is_active' => true,
                ]
            );
        }
    }
}
