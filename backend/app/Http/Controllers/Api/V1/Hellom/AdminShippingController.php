<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Services\Shipping\ShippingException;
use App\Services\Shipping\ShippingService;
use App\Services\Shipping\ShippingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super admin › Pengaturan › Ongkir (RajaOngkir): provider, API key, couriers, connection test. */
class AdminShippingController extends BaseApiController
{
    public function __construct(private readonly ShippingSettings $settings)
    {
    }

    public function show(): JsonResponse
    {
        return $this->ok($this->settings->publicPayload(), 'Pengaturan ongkir');
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'in:' . implode(',', ShippingSettings::PROVIDERS)],
            'api_key' => ['nullable', 'string', 'max:200'],
            'base_url' => ['nullable', 'url:https', 'max:200'],
            'couriers' => ['required', 'array', 'min:1'],
            'couriers.*' => ['string', 'in:' . implode(',', array_keys(ShippingSettings::COURIERS))],
            'cache_hours' => ['nullable', 'integer', 'min:1', 'max:72'],
        ], ['couriers.min' => 'Pilih minimal satu kurir.']);
        $before = $this->settings->all();
        $payload = $this->settings->update($validated);

        // Never log the key itself — only that it changed.
        $this->adminAudit($request, 'shipping.settings_updated', 'system_setting', null, [
            'provider' => [$before['provider'], $payload['provider']],
            'couriers' => $payload['couriers'],
            'api_key_changed' => !empty($validated['api_key']),
        ]);

        return $this->ok($payload, 'Pengaturan ongkir disimpan');
    }

    /** One destination search with the saved key (uses 1 hit of the provider quota). */
    public function test(ShippingService $shipping): JsonResponse
    {
        try {
            $found = $shipping->provider()->searchDestinations('jakarta', 1);
        } catch (ShippingException $e) {
            return $this->fail($e->getMessage(), ['code' => 'SHIPPING_TEST_FAILED'], 422);
        }

        return $this->ok(['ok' => true, 'sample' => $found[0]->label ?? null], 'Terhubung ke RajaOngkir');
    }
}
