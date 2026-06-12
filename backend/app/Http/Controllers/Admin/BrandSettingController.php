<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BrandSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandSettingController extends Controller
{
    public function edit()
    {
        $brand = BrandSetting::current();

        return view('admin.brand.edit', [
            'brand' => $brand,
            'fontOptions' => $this->fontOptions(),
        ]);
    }

    public function update(Request $request)
    {
        $brand = BrandSetting::current();
        if (!$brand) {
            abort(500, 'Brand settings not initialized. Run migrations.');
        }

        $hex = ['regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'];

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'about' => ['nullable', 'string', 'max:4000'],

            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'instagram' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'max:120'],

            'primary_color' => array_merge(['required'], $hex),
            'secondary_color' => array_merge(['required'], $hex),
            'accent_color' => array_merge(['required'], $hex),
            'background_color' => array_merge(['required'], $hex),

            'button_radius' => ['required', 'integer', 'min:0', 'max:40'],
            'font_family' => ['required', 'string', 'in:'.implode(',', array_keys($this->fontOptions()))],

            'customer_demo_mode_enabled' => ['nullable', 'boolean'],

            'google_maps_place_id' => ['nullable', 'string', 'max:255'],

            'logo_light' => ['nullable', 'image', 'max:2048'],
            'logo_dark' => ['nullable', 'image', 'max:2048'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico,svg', 'max:1024'],
            'home_banner_media' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif,mp4,webm', 'max:20480'],
        ]);

        $update = [
            'business_name' => $validated['business_name'],
            'tagline' => $validated['tagline'] ?? null,
            'about' => $validated['about'] ?? null,

            'phone' => $validated['phone'] ?? null,
            'whatsapp' => $validated['whatsapp'] ?? null,
            'address' => $validated['address'] ?? null,
            'instagram' => $validated['instagram'] ?? null,
            'website' => $validated['website'] ?? null,

            'primary_color' => $validated['primary_color'],
            'secondary_color' => $validated['secondary_color'],
            'accent_color' => $validated['accent_color'],
            'background_color' => $validated['background_color'],

            'button_radius' => (int) $validated['button_radius'],
            'font_family' => $validated['font_family'],

            'customer_demo_mode_enabled' => (bool) ($validated['customer_demo_mode_enabled'] ?? false),

            'google_maps_place_id' => $validated['google_maps_place_id'] ?? null,
        ];

        if ($request->hasFile('logo_light')) {
            $path = $request->file('logo_light')->store('brand', 'public');
            if ($brand->logo_light_path) {
                Storage::disk('public')->delete($brand->logo_light_path);
            }
            $update['logo_light_path'] = $path;
        }

        if ($request->hasFile('logo_dark')) {
            $path = $request->file('logo_dark')->store('brand', 'public');
            if ($brand->logo_dark_path) {
                Storage::disk('public')->delete($brand->logo_dark_path);
            }
            $update['logo_dark_path'] = $path;
        }

        if ($request->hasFile('favicon')) {
            $path = $request->file('favicon')->store('brand', 'public');
            if ($brand->favicon_path) {
                Storage::disk('public')->delete($brand->favicon_path);
            }
            $update['favicon_path'] = $path;
        }

        if ($request->hasFile('home_banner_media')) {
            $file = $request->file('home_banner_media');
            $path = $file->store('brand', 'public');
            if ($brand->home_banner_media_path) {
                Storage::disk('public')->delete($brand->home_banner_media_path);
            }

            $update['home_banner_media_path'] = $path;
            $update['home_banner_media_mime'] = $file->getMimeType();
        }

        $brand->update($update);
        BrandSetting::forgetCache();

        return back()->with('success', 'Brand berhasil disimpan.');
    }

    private function fontOptions(): array
    {
        return [
            'system-ui' => 'System (Default)',
            'ui-sans-serif' => 'Sans (ui-sans-serif)',
            'ui-serif' => 'Serif (ui-serif)',
            'ui-monospace' => 'Monospace (ui-monospace)',
        ];
    }
}
