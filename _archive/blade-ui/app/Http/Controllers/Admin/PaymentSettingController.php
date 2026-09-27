<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PaymentSettingController extends Controller
{
    public function edit()
    {
        $setting = PaymentSetting::current();

        return view('admin.payments.edit', [
            'setting' => $setting,
            'midtransConfigured' => (string) config('payments.providers.midtrans.server_key') !== '',
        ]);
    }

    public function update(Request $request)
    {
        $setting = PaymentSetting::current();
        if (!$setting) {
            abort(500, 'Payment settings not initialized. Run migrations.');
        }

        $validated = $request->validate([
            'cash_enabled' => ['nullable', 'boolean'],
            'qris_static_enabled' => ['nullable', 'boolean'],
            'qris_dynamic_enabled' => ['nullable', 'boolean'],
            'default_method' => ['required', 'string', Rule::in(['cash', 'qris_static', 'qris_dynamic'])],
            'dynamic_provider' => ['required', 'string', Rule::in(['midtrans'])],
            'dynamic_sandbox' => ['nullable', 'boolean'],
            'auto_complete_when_paid' => ['nullable', 'boolean'],
            'require_paid_before_complete' => ['nullable', 'boolean'],
            'require_paid_before_submit' => ['nullable', 'boolean'],

            'qris_static_payload' => ['nullable', 'string', 'max:5000'],
            'qris_static_image' => ['nullable', 'image', 'max:4096'],
            'remove_qris_static_image' => ['nullable', 'boolean'],
        ]);

        $update = [
            'cash_enabled' => (bool) ($validated['cash_enabled'] ?? false),
            'qris_static_enabled' => (bool) ($validated['qris_static_enabled'] ?? false),
            'qris_dynamic_enabled' => (bool) ($validated['qris_dynamic_enabled'] ?? false),
            'default_method' => (string) $validated['default_method'],
            'dynamic_provider' => (string) $validated['dynamic_provider'],
            'dynamic_sandbox' => (bool) ($validated['dynamic_sandbox'] ?? false),
            'qris_static_payload' => ($validated['qris_static_payload'] ?? '') !== '' ? (string) $validated['qris_static_payload'] : null,
            'auto_complete_when_paid' => (bool) ($validated['auto_complete_when_paid'] ?? false),
            'require_paid_before_complete' => (bool) ($validated['require_paid_before_complete'] ?? false),
            'require_paid_before_submit' => (bool) ($validated['require_paid_before_submit'] ?? false),
        ];

        if (($validated['remove_qris_static_image'] ?? false) && $setting->qris_static_image_path) {
            Storage::disk('public')->delete($setting->qris_static_image_path);
            $update['qris_static_image_path'] = null;
        }

        if ($request->hasFile('qris_static_image')) {
            $path = $request->file('qris_static_image')->store('payments', 'public');
            if ($setting->qris_static_image_path) {
                Storage::disk('public')->delete($setting->qris_static_image_path);
            }
            $update['qris_static_image_path'] = $path;
        }

        // Ensure at least one method enabled
        if (!$update['cash_enabled'] && !$update['qris_static_enabled'] && !$update['qris_dynamic_enabled']) {
            return back()->withErrors(['cash_enabled' => 'Minimal aktifkan 1 metode pembayaran.'])->withInput();
        }

        // Ensure default is enabled
        $default = $update['default_method'];
        $defaultEnabled = (
            ($default === 'cash' && $update['cash_enabled']) ||
            ($default === 'qris_static' && $update['qris_static_enabled']) ||
            ($default === 'qris_dynamic' && $update['qris_dynamic_enabled'])
        );
        if (!$defaultEnabled) {
            return back()->withErrors(['default_method' => 'Default method harus termasuk yang diaktifkan.'])->withInput();
        }

        $setting->update($update);
        PaymentSetting::forgetCache();

        return back()->with('success', 'Pengaturan pembayaran disimpan.');
    }
}
