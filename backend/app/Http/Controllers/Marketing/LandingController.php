<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\BrandSetting;
use App\Models\LandingPageSetting;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function landing(Request $request)
    {
        $settings = LandingPageSetting::current();
        $brand = BrandSetting::current();

        return view('marketing.landing', compact('settings', 'brand'));
    }

    public function features(Request $request)
    {
        return view('marketing.features');
    }

    public function pricing(Request $request)
    {
        return view('marketing.pricing');
    }
}
