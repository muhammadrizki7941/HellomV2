<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BrandSetting extends Model
{
    protected $fillable = [
        'business_name',
        'tagline',
        'about',
        'phone',
        'whatsapp',
        'address',
        'instagram',
        'website',
        'logo_light_path',
        'logo_dark_path',
        'favicon_path',
        'home_banner_media_path',
        'home_banner_media_mime',
        'primary_color',
        'secondary_color',
        'accent_color',
        'background_color',
        'background_gradient',
        'background_pattern',
        'background_image_path',
        'background_overlay_opacity',
        'button_radius',
        'font_family',
        'customer_demo_mode_enabled',
        'google_maps_place_id',
    ];

    protected $casts = [
        'button_radius' => 'integer',
        'customer_demo_mode_enabled' => 'boolean',
        'background_overlay_opacity' => 'decimal:2',
    ];

    public static function current(): ?self
    {
        if (!Schema::hasTable('brand_settings')) {
            return null;
        }

        $cacheKey = 'brand_settings.current';

        return Cache::rememberForever($cacheKey, function () {
            $brand = static::query()->first();
            if ($brand) {
                return $brand;
            }

            return static::query()->create([
                'business_name' => 'Self Order',
                'tagline' => 'Cafe & Resto',
                'primary_color' => '#0f172a',
                'secondary_color' => '#334155',
                'accent_color' => '#10b981',
                'background_color' => '#f8fafc',
                'button_radius' => 18,
                'font_family' => 'system-ui',
            ]);
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget('brand_settings.current');
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

        // Storage disk('public') paths are typically stored like "brand/xxx.png".
        // Return a relative URL so it works even when APP_URL is different from the current host.
        if (Str::startsWith($path, 'storage/')) {
            $path = Str::after($path, 'storage/');
        }
        if (Str::startsWith($path, 'media/')) {
            $path = Str::after($path, 'media/');
        }

        return $publicBase . '/' . ltrim($path, '/');
    }

    public function logoLightUrl(): ?string
    {
        return $this->publicAssetUrl($this->logo_light_path);
    }

    public function logoDarkUrl(): ?string
    {
        return $this->publicAssetUrl($this->logo_dark_path);
    }

    public function faviconUrl(): ?string
    {
        return $this->publicAssetUrl($this->favicon_path);
    }

    public function homeBannerMediaUrl(): ?string
    {
        return $this->publicAssetUrl($this->home_banner_media_path);
    }

    public function backgroundImageUrl(): ?string
    {
        return $this->publicAssetUrl($this->background_image_path);
    }

    public function homeBannerIsVideo(): bool
    {
        return Str::startsWith((string) ($this->home_banner_media_mime ?? ''), 'video/');
    }

    public function getGoogleMapsRating(): ?array
    {
        // TEMPORARY: Return test data to verify display works
        if ($this->google_maps_place_id) {
            return [
                'rating' => 4.7,
                'user_ratings_total' => 128
            ];
        }

        return null;
    }
}
