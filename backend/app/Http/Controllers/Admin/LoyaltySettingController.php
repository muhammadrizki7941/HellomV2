<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoyaltySetting;
use Illuminate\Http\Request;

class LoyaltySettingController extends Controller
{
    public function edit()
    {
        $setting = LoyaltySetting::current();

        return view('admin.loyalty.edit', [
            'setting' => $setting,
        ]);
    }

    public function update(Request $request)
    {
        $setting = LoyaltySetting::current();
        if (!$setting) {
            abort(500, 'Loyalty settings not initialized. Run migrations.');
        }

        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'earn_method' => ['required', 'string', 'in:per_1000,per_unit,per_min_spend,flat'],

            // Legacy
            'points_per_1000' => ['required', 'integer', 'min:0', 'max:1000000'],

            // Per unit
            'points_unit_amount' => ['required', 'integer', 'min:1', 'max:2000000000'],
            'points_per_unit' => ['required', 'integer', 'min:0', 'max:2000000000'],

            // Per min spend
            'points_per_min_spend' => ['required', 'integer', 'min:0', 'max:2000000000'],

            // Flat
            'flat_points_per_order' => ['required', 'integer', 'min:0', 'max:2000000000'],

            'min_spend_amount' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'max_points_per_order' => ['nullable', 'integer', 'min:1', 'max:2000000000'],

            // Redeem
            'redeem_enabled' => ['nullable', 'boolean'],
            'redeem_rp_per_point' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'redeem_min_spend_amount' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'redeem_max_points_per_order' => ['nullable', 'integer', 'min:1', 'max:2000000000'],
            'redeem_max_discount_rp' => ['nullable', 'integer', 'min:1', 'max:2000000000'],
        ]);

        $setting->update([
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'earn_method' => (string) $validated['earn_method'],
            'points_per_1000' => (int) $validated['points_per_1000'],
            'points_unit_amount' => (int) $validated['points_unit_amount'],
            'points_per_unit' => (int) $validated['points_per_unit'],
            'min_spend_amount' => (int) $validated['min_spend_amount'],
            'points_per_min_spend' => (int) $validated['points_per_min_spend'],
            'flat_points_per_order' => (int) $validated['flat_points_per_order'],
            'max_points_per_order' => $validated['max_points_per_order'] !== null ? (int) $validated['max_points_per_order'] : null,

            'redeem_enabled' => (bool) ($validated['redeem_enabled'] ?? false),
            'redeem_rp_per_point' => (int) $validated['redeem_rp_per_point'],
            'redeem_min_spend_amount' => (int) $validated['redeem_min_spend_amount'],
            'redeem_max_points_per_order' => $validated['redeem_max_points_per_order'] !== null ? (int) $validated['redeem_max_points_per_order'] : null,
            'redeem_max_discount_rp' => $validated['redeem_max_discount_rp'] !== null ? (int) $validated['redeem_max_discount_rp'] : null,
        ]);

        LoyaltySetting::forgetCache();

        return back()->with('success', 'Pengaturan poin disimpan.');
    }
}
