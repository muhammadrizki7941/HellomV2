<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\SitePromotion;

class PromoPageController extends Controller
{
    public function __invoke()
    {
        $promos = SitePromotion::query()
            ->activeForCustomer()
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get();

        $brand = \App\Models\BrandSetting::current();

        return view('customer.promo.index', [
            'promos' => $promos,
            'brand' => $brand,
        ]);
    }
}
