<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\HellomBrandSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandSettingController extends BaseApiController
{
    public function publicShow(): JsonResponse
    {
        $settings = HellomBrandSetting::getSettings();

        return $this->ok([
            'brand' => $this->transformBrand($settings),
        ], 'Brand settings retrieved');
    }

    public function update(Request $request): JsonResponse
    {
        $settings = HellomBrandSetting::getSettings();

        $validated = $request->validate([
            'app_name' => 'nullable|string|max:100',
            'business_name' => 'nullable|string|max:100',
            'tagline' => 'nullable|string|max:200',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp,svg|max:2048',
            'favicon' => 'nullable|image|mimes:jpg,jpeg,png,ico,webp,svg|max:512',
            'primary_color' => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
            'accent_color' => 'nullable|string|max:20',
            'background_color' => 'nullable|string|max:20',
            'login_title' => 'nullable|string|max:100',
            'login_subtitle' => 'nullable|string|max:200',
            'register_title' => 'nullable|string|max:100',
            'register_subtitle' => 'nullable|string|max:200',
            'footer_text' => 'nullable|string|max:200',
            'support_email' => 'nullable|email|max:100',
            'support_phone' => 'nullable|string|max:30',
            'social_instagram' => 'nullable|url|max:200',
            'social_facebook' => 'nullable|url|max:200',
            'social_tiktok' => 'nullable|url|max:200',
            'meta_title' => 'nullable|string|max:100',
            'meta_description' => 'nullable|string|max:300',
        ]);

        if ($request->hasFile('logo')) {
            if ($settings->logo_path) {
                Storage::disk('public')->delete($settings->logo_path);
            }
            $validated['logo_path'] = $request->file('logo')->store('brand', 'public');
        }
        unset($validated['logo']);

        if ($request->hasFile('favicon')) {
            if ($settings->favicon_path) {
                Storage::disk('public')->delete($settings->favicon_path);
            }
            $validated['favicon_path'] = $request->file('favicon')->store('brand', 'public');
        }
        unset($validated['favicon']);

        $appName = $validated['app_name'] ?? $validated['business_name'] ?? null;
        if ($appName !== null) {
            $validated['app_name'] = $appName;
            $validated['business_name'] = $appName;
        }

        $settings->fill($validated);

        $settings->save();

        return $this->ok([
            'brand' => $this->transformBrand($settings->fresh()),
        ], 'Brand settings updated');
    }

    private function transformBrand(HellomBrandSetting $settings): array
    {
        $appName = $settings->app_name ?: ($settings->business_name ?: 'Hellom');

        return [
            'app_name' => $appName,
            'business_name' => $appName,
            'tagline' => $settings->tagline,
            'logo_url' => $settings->logoUrl(),
            'logo_base64' => $this->getBase64($settings->logo_path),
            'logo_dark_url' => $settings->logoDarkUrl(),
            'favicon_url' => $settings->faviconUrl(),
            'primary_color' => $settings->primary_color ?: '#0c0c0c',
            'secondary_color' => $settings->secondary_color ?: '#334155',
            'accent_color' => $settings->accent_color ?: '#c8ff47',
            'background_color' => $settings->background_color ?: '#0c0c0c',
            'login_bg_image' => $settings->login_bg_image,
            'login_title' => $settings->login_title ?: 'Selamat datang lagi',
            'login_subtitle' => $settings->login_subtitle ?: 'Masuk ke akun kamu dan lanjutkan kerja hari ini.',
            'register_title' => $settings->register_title ?: 'Bikin akun baru',
            'register_subtitle' => $settings->register_subtitle ?: 'Gabung dan mulai kelola bisnis kamu bareng Hellom.',
            'footer_text' => $settings->footer_text ?: '© 2026 Hellom. All rights reserved.',
            'support_email' => $settings->support_email,
            'support_phone' => $settings->support_phone,
            'social_instagram' => $settings->social_instagram,
            'social_facebook' => $settings->social_facebook,
            'social_tiktok' => $settings->social_tiktok,
            'meta_title' => $settings->meta_title ?: $appName,
            'meta_description' => $settings->meta_description,
        ];
    }

    private function getBase64(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $fullPath = storage_path('app/public/' . ltrim($path, '/'));
        if (!is_file($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);
        $mime = mime_content_type($fullPath) ?: 'application/octet-stream';

        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }
}
