<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Api\V1\Hellom\Concerns\ResolvesSellerOrganization;
use App\Models\LandingTrackingSetting;
use App\Services\Landing\LandingShop;
use App\Services\Landing\LandingStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Hellom Page ads & stats (Fase 4): pixel/analytics ids per shop and the traffic report. */
class SellerMarketingController extends BaseApiController
{
    use ResolvesSellerOrganization;

    public function tracking(Request $request): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }

        return $this->ok($this->payload(LandingTrackingSetting::query()->find($organization->id)), 'Pengaturan iklan');
    }

    public function updateTracking(Request $request, LandingShop $shop): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        // Empty strings clear a field; anything else must match the id format (printed into public pages).
        $request->merge(collect($request->only(array_keys(LandingTrackingSetting::PATTERNS)))
            ->map(fn ($v) => is_string($v) ? trim($v) : $v)->all());
        $rules = [];
        foreach (LandingTrackingSetting::PATTERNS as $key => $pattern) {
            $rules[$key] = ['nullable', 'string', 'regex:' . $pattern];
        }
        $rules['meta_capi_token'] = ['nullable', 'string', 'min:40', 'max:600', 'regex:/^[A-Za-z0-9_\-]+$/'];
        $rules['clear_meta_capi_token'] = ['sometimes', 'boolean'];
        $validated = $request->validate($rules, [
            'meta_pixel_id.regex' => 'Meta Pixel ID berupa 10–20 angka.',
            'ga4_id.regex' => 'Format GA4: G-XXXXXXX.',
            'google_ads_id.regex' => 'Format Google Ads: AW-123456789.',
            'google_ads_label.regex' => 'Label konversi hanya huruf, angka, - dan _.',
            'tiktok_pixel_id.regex' => 'TikTok Pixel ID berupa huruf besar dan angka (15–25 karakter).',
            'meta_test_event_code.regex' => 'Kode uji berformat TEST12345.',
            'meta_capi_token.regex' => 'Token Conversions API tidak valid.',
        ]);

        $settings = LandingTrackingSetting::query()->firstOrNew(['organization_id' => $organization->id]);
        foreach (array_keys(LandingTrackingSetting::PATTERNS) as $key) {
            if (array_key_exists($key, $validated)) {
                $settings->{$key} = $validated[$key] ?: null;
            }
        }
        if (!empty($validated['meta_capi_token'])) {
            $settings->meta_capi_token = $validated['meta_capi_token'];
        } elseif (!empty($validated['clear_meta_capi_token'])) {
            $settings->meta_capi_token = null;
        }
        $settings->save();
        $shop->bumpCache((int) $organization->id);

        return $this->ok($this->payload($settings), 'Pengaturan iklan disimpan');
    }

    public function stats(Request $request, LandingStats $stats): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $days = in_array((int) $request->query('days', 30), [7, 30, 90], true) ? (int) $request->query('days', 30) : 30;

        return $this->ok($stats->report((int) $organization->id, $days), 'Statistik');
    }

    /** @return array<string, mixed> the CAPI token itself is never returned */
    private function payload(?LandingTrackingSetting $s): array
    {
        return [
            'meta_pixel_id' => $s?->meta_pixel_id,
            'meta_capi_token_set' => (string) ($s?->meta_capi_token ?? '') !== '',
            'meta_test_event_code' => $s?->meta_test_event_code,
            'ga4_id' => $s?->ga4_id,
            'google_ads_id' => $s?->google_ads_id,
            'google_ads_label' => $s?->google_ads_label,
            'tiktok_pixel_id' => $s?->tiktok_pixel_id,
        ];
    }
}
