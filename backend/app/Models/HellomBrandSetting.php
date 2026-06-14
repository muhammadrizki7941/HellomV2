<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HellomBrandSetting extends Model
{
    protected $table = 'hellom_brand_settings';

    protected $fillable = [
        'app_name',
        'logo_path',
        'logo_dark_path',
        'favicon_path',
        'business_name',
        'tagline',
        'primary_color',
        'secondary_color',
        'accent_color',
        'background_color',
        'login_bg_image',
        'login_title',
        'login_subtitle',
        'register_title',
        'register_subtitle',
        'footer_text',
        'support_email',
        'support_phone',
        'social_instagram',
        'social_facebook',
        'social_tiktok',
        'meta_title',
        'meta_description',
    ];

    protected $casts = [
        'app_name' => 'string',
        'primary_color' => 'string',
        'secondary_color' => 'string',
        'accent_color' => 'string',
        'background_color' => 'string',
        'logo_path' => 'string',
        'logo_dark_path' => 'string',
        'favicon_path' => 'string',
    ];

    public static function getSettings(): self
    {
        $settings = static::first();

        if (!$settings) {
            $settings = static::create([
                'app_name' => 'Hellom',
                'business_name' => 'Hellom',
                'tagline' => 'Solusi kasir modern untuk UMKM',
                'primary_color' => '#0c0c0c',
                'secondary_color' => '#334155',
                'accent_color' => '#c8ff47',
                'background_color' => '#0c0c0c',
                'login_title' => 'Selamat datang lagi',
                'login_subtitle' => 'Masuk ke akun kamu dan lanjutkan kerja hari ini.',
                'register_title' => 'Bikin akun baru',
                'register_subtitle' => 'Gabung dan mulai kelola bisnis kamu bareng Hellom.',
                'footer_text' => 'Â© 2026 Hellom. All rights reserved.',
                'meta_title' => 'Hellom',
            ]);
        }

        return $settings;
    }

    /**
     * Base application URL without trailing slash, e.g. http://127.0.0.1:8000
     */
    protected function appBaseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * Build an absolute URL for a path stored on the 'public' disk
     * (accessible via the public/storage symlink).
     * Always returns an absolute URL based on APP_URL so it works correctly
     * even when the frontend runs on a different origin/port (e.g. localhost:3000)
     * than the backend (e.g. 127.0.0.1:8000).
     */
    protected function absoluteStorageUrl(string $path): string
    {
        return $this->appBaseUrl() . '/storage/' . ltrim($path, '/');
    }

    /**
     * Build an absolute URL for a static file under public/, e.g. public/brand/logo.png
     */
    protected function absolutePublicUrl(string $relativePath): string
    {
        return $this->appBaseUrl() . '/' . ltrim($relativePath, '/');
    }

    public function logoUrl(): ?string
    {
        // Priority 1: Database stored path (from upload)
        if (!empty($this->logo_path)) {
            return $this->absoluteStorageUrl($this->logo_path);
        }

        // Priority 2: Static file in public/brand/logo.png
        if (file_exists(public_path('brand/logo.png'))) {
            return $this->absolutePublicUrl('brand/logo.png');
        }

        // Priority 3: Static file in public/assets/hellom.png (fallback)
        if (file_exists(public_path('assets/hellom.png'))) {
            return $this->absolutePublicUrl('assets/hellom.png');
        }

        return null;
    }

    public function logoDarkUrl(): ?string
    {
        // Priority 1: Database stored path (from upload)
        if (!empty($this->logo_dark_path)) {
            return $this->absoluteStorageUrl($this->logo_dark_path);
        }

        // Priority 2: Static file in public/brand/logo-dark.png
        if (file_exists(public_path('brand/logo-dark.png'))) {
            return $this->absolutePublicUrl('brand/logo-dark.png');
        }

        // Fallback to logo
        return $this->logoUrl();
    }

    public function faviconUrl(): ?string
    {
        // Priority 1: Database stored path (from upload)
        if (!empty($this->favicon_path)) {
            return $this->absoluteStorageUrl($this->favicon_path);
        }

        // Priority 2: Static file in public/brand/favicon.ico
        if (file_exists(public_path('brand/favicon.ico'))) {
            return $this->absolutePublicUrl('brand/favicon.ico');
        }

        // Priority 3: Static file in public/brand/favicon.png
        if (file_exists(public_path('brand/favicon.png'))) {
            return $this->absolutePublicUrl('brand/favicon.png');
        }

        return null;
    }
}