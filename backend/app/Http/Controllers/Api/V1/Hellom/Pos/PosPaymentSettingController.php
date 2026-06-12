<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\Organization;
use App\Models\PosPaymentSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PosPaymentSettingController extends BasePosController
{
    public function index(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $setting = PosPaymentSetting::firstOrCreate(
            ['tenant_id' => $tenantSlug],
            ['cash_enabled' => true]
        );

        // Tambahkan URL QRIS jika ada
        $data = $setting->toArray();
        $data['qris_image_url'] = $setting->qris_image_path
            ? url('storage/' . $setting->qris_image_path)
            : null;

        return response()->json([
            'success' => true,
            'data'    => ['payment_settings' => $data],
            'message' => 'Setting pembayaran berhasil dimuat',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $validated = $request->validate([
            'cash_enabled'           => 'nullable|in:true,false,1,0',
            'cash_label'             => 'nullable|string|max:50',
            'transfer_enabled'       => 'nullable|in:true,false,1,0',
            'transfer_bank_name'     => 'nullable|string|max:50',
            'transfer_account_number'=> 'nullable|string|max:30',
            'transfer_account_name'  => 'nullable|string|max:100',
            'gopay_enabled'          => 'nullable|in:true,false,1,0',
            'gopay_number'           => 'nullable|string|max:20',
            'gopay_name'             => 'nullable|string|max:100',
            'dana_enabled'           => 'nullable|in:true,false,1,0',
            'dana_number'            => 'nullable|string|max:20',
            'dana_name'              => 'nullable|string|max:100',
            'qris_enabled'           => 'nullable|in:true,false,1,0',
            'qris_image'             => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'qris_label'             => 'nullable|string|max:50',
        ]);

        // Cast boolean dari FormData string
        $boolFields = [
            'cash_enabled','transfer_enabled',
            'gopay_enabled','dana_enabled','qris_enabled'
        ];
        foreach ($boolFields as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = filter_var(
                    $validated[$field], FILTER_VALIDATE_BOOLEAN
                );
            }
        }

        // Handle upload QRIS image
        if ($request->hasFile('qris_image')) {
            $setting = PosPaymentSetting::where('tenant_id', $tenantSlug)->first();
            if ($setting?->qris_image_path) {
                Storage::disk('public')->delete($setting->qris_image_path);
            }
            $validated['qris_image_path'] = $request->file('qris_image')
                ->store('pos/qris/' . $tenantSlug, 'public');
        }
        unset($validated['qris_image']);

        $setting = PosPaymentSetting::updateOrCreate(
            ['tenant_id' => $tenantSlug],
            $validated
        );

        $data = $setting->fresh()->toArray();
        $data['qris_image_url'] = $setting->qris_image_path
            ? url('storage/' . $setting->qris_image_path)
            : null;

        return response()->json([
            'success' => true,
            'data'    => ['payment_settings' => $data],
            'message' => 'Setting pembayaran berhasil disimpan!',
        ]);
    }

    public function publicSettings(string $tenantSlug): JsonResponse
    {
        $resolvedTenantSlug = $this->resolveTenantSlug($tenantSlug);

        $setting = PosPaymentSetting::where('tenant_id', $resolvedTenantSlug)
            ->first();

        if (!$setting) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'payment_methods' => [
                        [
                            'key'   => 'cash',
                            'label' => 'Tunai',
                            'icon'  => 'cash',
                            'info'  => 'Bayar langsung ke kasir',
                        ],
                        [
                            'key'            => 'transfer',
                            'label'          => 'Transfer Bank',
                            'icon'           => 'transfer',
                            'bank_name'      => 'BCA',
                            'account_number' => '1234567890',
                            'account_name'   => 'Contoh Restaurant',
                            'info'           => 'Transfer ke rekening BCA',
                        ],
                        [
                            'key'       => 'gopay',
                            'label'     => 'GoPay',
                            'icon'      => 'gopay',
                            'number'    => '081234567890',
                            'name'      => 'Contoh Restaurant',
                            'deep_link' => 'gojek://gopay/merchant?phone=+6281234567890',
                            'info'      => 'Transfer GoPay ke 081234567890',
                        ],
                        [
                            'key'        => 'dana',
                            'label'     => 'DANA',
                            'icon'      => 'dana',
                            'number'    => '081234567890',
                            'name'      => 'Contoh Restaurant',
                            'deep_link' => 'dana://wallet/deeplink/link?phoneNo=+6281234567890',
                            'info'      => 'Transfer Dana ke 081234567890',
                        ],
                        [
                            'key'        => 'qris',
                            'label'     => 'QRIS',
                            'icon'      => 'qris',
                            'qris_image' => null,
                            'info'      => 'Scan QR dengan semua e-wallet',
                        ]
                    ]
                ],
            ]);
        }

        $methods = [];

        if ($setting->cash_enabled) {
            $methods[] = [
                'key'   => 'cash',
                'label' => $setting->cash_label ?: 'Tunai',
                'icon'  => 'cash',
                'info'  => 'Bayar langsung ke kasir',
            ];
        }

        if ($setting->transfer_enabled
            && $setting->transfer_account_number) {
            $methods[] = [
                'key'            => 'transfer',
                'label'          => 'Transfer '
                    . ($setting->transfer_bank_name ?: 'Bank'),
                'icon'           => 'transfer',
                'bank_name'      => $setting->transfer_bank_name,
                'account_number' => $setting->transfer_account_number,
                'account_name'   => $setting->transfer_account_name,
                'info'           => 'Transfer ke rekening '
                    . $setting->transfer_bank_name,
            ];
        }

        if ($setting->gopay_enabled && $setting->gopay_number) {
            // Format nomor: 08xx â†’ +628xx untuk deep link
            $phone = $setting->gopay_number;
            if (str_starts_with($phone, '0')) {
                $phone = '+62' . substr($phone, 1);
            }
            $methods[] = [
                'key'       => 'gopay',
                'label'     => 'GoPay',
                'icon'      => 'gopay',
                'number'    => $setting->gopay_number,
                'name'      => $setting->gopay_name,
                'deep_link' => 'gojek://gopay/merchant?phone=' . $phone,
                'info'      => 'Transfer GoPay ke ' . $setting->gopay_number,
            ];
        }

        if ($setting->dana_enabled && $setting->dana_number) {
            $phone = $setting->dana_number;
            if (str_starts_with($phone, '0')) {
                $phone = '+62' . substr($phone, 1);
            }
            $methods[] = [
                'key'       => 'dana',
                'label'     => 'Dana',
                'icon'      => 'dana',
                'number'    => $setting->dana_number,
                'name'      => $setting->dana_name,
                'deep_link' => 'dana://wallet/deeplink/link?phoneNo='
                    . $phone,
                'info'      => 'Transfer Dana ke ' . $setting->dana_number,
            ];
        }

        if ($setting->qris_enabled) {
            $methods[] = [
                'key'        => 'qris',
                'label'      => $setting->qris_label ?: 'QRIS',
                'icon'       => 'qris',
                // Kirim sebagai base64 â€” tidak ada CORS issue
                'qris_image' => $this->toBase64($setting->qris_image_path),
                'info'       => 'Scan QR dengan semua e-wallet',
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => ['payment_methods' => $methods],
            'message' => 'Metode pembayaran berhasil dimuat',
        ]);
    }

    private function resolveTenantSlug(string $organizationOrTenantSlug): string
    {
        $organization = Organization::query()
            ->where('slug', $organizationOrTenantSlug)
            ->orWhere('pos_tenant_slug', $organizationOrTenantSlug)
            ->first();

        return (string) ($organization?->pos_tenant_slug ?: $organization?->slug ?: $organizationOrTenantSlug);
    }

    private function toBase64(?string $storagePath): ?string
    {
        if (!$storagePath) return null;
        $fullPath = storage_path('app/public/' . $storagePath);
        if (!file_exists($fullPath)) return null;
        $mime = mime_content_type($fullPath);
        $data = base64_encode(file_get_contents($fullPath));
        return "data:{$mime};base64,{$data}";
    }

    private function formatPhoneForDeepLink(string $phone): string
    {
        // Hapus semua non-digit
        $clean = preg_replace('/\D/', '', $phone);

        // Jika mulai dengan 0, ganti jadi +62
        if (str_starts_with($clean, '0')) {
            return '+62' . substr($clean, 1);
        }

        // Jika sudah +62, return as is
        if (str_starts_with($clean, '62')) {
            return '+' . $clean;
        }

        // Default +62
        return '+62' . $clean;
    }
}
