<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\BrandSetting;
use App\Models\SitePromotion;
use Illuminate\Http\Request;

class HomePageController extends Controller
{
    public function __invoke(Request $request)
    {
        $now = now();

        $featuredPackages = Product::query()
            ->where('is_package', true)
            ->where('show_as_banner', true)
            ->where('is_available', true)
            ->whereHas('categories', fn ($q) => $q->where('is_active', true))
            ->where(function ($q) use ($now) {
                $q->whereNull('banner_starts_at')
                    ->orWhere('banner_starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('banner_ends_at')
                    ->orWhere('banner_ends_at', '>=', $now);
            })
            ->with(['packageItems.itemProduct'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(10)
            ->get();

        $promos = SitePromotion::query()
            ->activeForCustomer()
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get();

        $brand = BrandSetting::current();

        return view('customer.home', [
            'featuredPackages' => $featuredPackages,
            'promos' => $promos,
            'brand' => $brand,
        ]);
    }
}
