<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LandingPageSetting extends Model
{
    protected $fillable = [
        'hero_badge',
        'hero_title',
        'hero_subtitle',
        'hero_cta_primary',
        'hero_cta_secondary',
        'hero_trial_text',
        'problems',
        'problems_solution',
        'features_title',
        'features_subtitle',
        'features',
        'pricing_title',
        'pricing_subtitle',
        'pricing_plans',
        'comparison_title',
        'comparison_features',
        'final_cta_title',
        'final_cta_subtitle',
        'final_cta_button',
        'final_cta_footer',
        'footer_text',
        'banner_image_path',
    ];

    protected $casts = [
        'problems' => 'array',
        'features' => 'array',
        'pricing_plans' => 'array',
        'comparison_features' => 'array',
    ];

    public static function current(): ?self
    {
        return Cache::rememberForever('landing_page_settings', function () {
            return static::first() ?? static::createDefault();
        });
    }

    private static function createDefault(): self
    {
        return static::create([
            'hero_badge' => 'Sistem Pilihan UMKM F&B 2024',
            'hero_title' => 'Kasir + Self Order Online. Satu Sistem. Tanpa Ribet.',
            'hero_subtitle' => 'Hellom POS membantu UMKM F&B menerima order self order dan kasir dalam satu sistem yang rapi dan terjangkau.',
            'hero_cta_primary' => 'Coba Hellom POS Gratis',
            'hero_cta_secondary' => 'Lihat Demo Self Order',
            'hero_trial_text' => 'Trial Gratis 1 Minggu • Tanpa Kartu Kredit',
            'problems' => ["Manual order rawan salah", "Kasir ribet dan mahal", "Menu sulit diupdate secara instan", "Branding usaha kurang profesional"],
            'problems_solution' => 'Hellom POS hadir sebagai solusi praktis.',
            'features_title' => 'Keunggulan Hellom POS',
            'features_subtitle' => 'Bukan sekadar kasir, tapi partner pertumbuhan bisnis Anda.',
            'features' => [
                ["title" => "Real-Time Self Order", "description" => "Pelanggan pesan langsung dari meja, order langsung masuk ke sistem dapur.", "icon" => "01"],
                ["title" => "Custom Domain Usaha", "description" => "Gunakan domain sendiri (.com / .id) agar usaha kamu terlihat lebih profesional dan lebih mudah ditemukan di mesin pencari Google.", "icon" => "🌐"],
                ["title" => "Web Profil Tenant", "description" => "Punya halaman profil usaha sendiri di web untuk meningkatkan branding secara instan.", "icon" => "03"],
                ["title" => "Mode Kasir Manual", "description" => "Input pesanan langsung dari kasir untuk pelanggan yang tidak ingin self-order.", "icon" => "04"],
                ["title" => "Semua Device", "description" => "Jalan lancar di HP, tablet, atau PC. Tanpa perlu download atau install aplikasi apapun.", "icon" => "05"],
                ["title" => "Metode Pembayaran", "description" => "Sistem mendukung pembayaran QRIS (Non-Tunai) dan juga input manual untuk pembayaran Tunai di kasir.", "icon" => "06"]
            ],
            'pricing_title' => 'Pilih Paket Sesuai Kebutuhan',
            'pricing_subtitle' => 'Transparan, jujur, dan terjangkau untuk skala UMKM.',
            'pricing_plans' => [
                ["name" => "Free Trial", "price" => "Gratis", "period" => "", "yearly_price" => "Gratis", "yearly_period" => "", "savings" => "", "description" => "Coba fitur lengkap selama seminggu penuh.", "features" => ["Semua Fitur Utama", "Aktif 7 Hari", ""], "button" => "Mulai Trial Gratis", "popular" => false],
                ["name" => "Paket Basic", "price" => "IDR 99k", "period" => "/ bln", "yearly_price" => "IDR 900k", "yearly_period" => "/ thn", "savings" => "Hemat IDR 288k", "description" => "Solusi hemat untuk manajemen operasional.", "features" => ["Kasir + Self Order", "Web Profil Usaha", "Laporan Penjualan", ""], "button" => "Pilih Paket Basic", "popular" => false],
                ["name" => "Paket Pro", "price" => "IDR 199k", "period" => "/ bln", "yearly_price" => "IDR 1.800k", "yearly_period" => "/ thn", "savings" => "Hemat IDR 588k", "description" => "Tingkatkan prestise usaha dengan domain sendiri.", "features" => ["Semua Fitur Basic", "Custom Domain (.com / .id)", "SEO & AI Discovery Ready", "Support Prioritas"], "button" => "Pilih Paket Pro", "popular" => true]
            ],
            'comparison_title' => 'Kenapa Hellom POS Cocok untuk UMKM?',
            'comparison_features' => [
                ["feature" => "Self Order System", "competitor" => "Berbayar Tambahan", "hellom" => "Sudah Termasuk"],
                ["feature" => "Custom Domain (.id / .com)", "competitor" => "Sangat Jarang", "hellom" => "Tersedia (Pro)"],
                ["feature" => "Opsi Pembayaran", "competitor" => "Biasanya Terbatas", "hellom" => "QRIS & Tunai Ready"],
                ["feature" => "Optimasi AI & Search", "competitor" => "Tidak Ada", "hellom" => "Sudah Teroptimasi"]
            ],
            'final_cta_title' => 'Jadikan Tenant Anda Online & Dikenal AI.',
            'final_cta_subtitle' => 'Gunakan domain sendiri dan biarkan pelanggan menemukan usaha Anda di Google dengan mudah.',
            'final_cta_button' => 'Coba Trial 1 Minggu Sekarang',
            'final_cta_footer' => 'Branding Profesional • QRIS & Tunai • SEO Ready',
            'footer_text' => '© 2024 Hellom POS Indonesia • Modern UMKM Partner',
        ]);
    }

    public static function forgetCache(): void
    {
        Cache::forget('landing_page_settings');
    }

    private function publicAssetUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $path = trim($path);
        $publicBase = '/' . trim((string) config('filesystems.disks.public.url', '/storage'), '/');

        // If the DB already stores a full URL, respect it.
        if (Str::startsWith($path, ['http://', 'https://', '//'])) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            if (is_string($parsedPath) && Str::contains($parsedPath, '/storage/')) {
                return $publicBase . '/' . ltrim(Str::after($parsedPath, '/storage/'), '/');
            }
            if (is_string($parsedPath) && Str::contains($parsedPath, '/media/')) {
                return $publicBase . '/' . ltrim(Str::after($parsedPath, '/media/'), '/');
            }
            return $path;
        }

        $path = ltrim($path, '/');

        // Storage disk('public') paths are typically stored like "landing/xxx.png".
        if (Str::startsWith($path, 'storage/')) {
            $path = Str::after($path, 'storage/');
        }
        if (Str::startsWith($path, 'media/')) {
            $path = Str::after($path, 'media/');
        }

        return $publicBase . '/' . ltrim($path, '/');
    }

    public function bannerImageUrl(): ?string
    {
        return $this->publicAssetUrl($this->banner_image_path);
    }
}
