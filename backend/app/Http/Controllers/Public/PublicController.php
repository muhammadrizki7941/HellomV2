<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\HellomBrandSetting;
use Illuminate\Contracts\View\View;

class PublicController extends Controller
{
    public function landing(): View
    {
        $brand = HellomBrandSetting::getSettings();
        $banners = Banner::query()
            ->active()
            ->orderBy('position')
            ->orderBy('order')
            ->orderByDesc('id')
            ->get();

        return view('public.landing', [
            'brand' => $brand,
            'banners' => $banners,
            'headerBanners' => $banners->where('position', 'header')->values(),
            'heroBanners' => $banners->where('position', 'hero')->values(),
            'pageTitle' => $brand->meta_title ?: ($brand->app_name ?: 'Hellom'),
            'metaDescription' => $brand->meta_description ?: 'Hellom membantu restoran dan bisnis F&B mengelola POS, landing page, promo, dan operasional dalam satu platform.',
            'canonicalUrl' => url('/'),
            'ogImage' => $brand->logoUrl() ?: url('/hellom/assets/logo-hellom.png'),
        ]);
    }
}
