<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Api\V1\Hellom\Concerns\ResolvesSellerOrganization;
use App\Models\LandingProduct;
use App\Models\Organization;
use App\Services\Shipping\ShippingException;
use App\Services\Shipping\ShippingRate;
use App\Services\Shipping\ShippingService;
use App\Services\Shipping\ShippingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Courier rates for Hellom Page physical products (RajaOngkir):
 *   public  — destination search, rates for a product to the buyer's place;
 *   seller  — the shop's ship-from place and the couriers it offers.
 */
class ShippingController extends BaseApiController
{
    use ResolvesSellerOrganization;

    public function __construct(private readonly ShippingService $shipping)
    {
    }

    /** GET /public/shipping/destinations?q= — sub-district / city / postcode search. */
    public function destinations(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:3', 'max:80']], [
            'q.min' => 'Ketik minimal 3 huruf nama kecamatan, kota, atau kode pos.',
        ]);
        try {
            return $this->ok(['items' => $this->shipping->searchDestinations($validated['q'])], 'Tujuan pengiriman');
        } catch (ShippingException $e) {
            return $this->fail($e->getMessage(), ['code' => 'SHIPPING_UNAVAILABLE'], 503);
        }
    }

    /** POST /public/landing-products/{publicId}/shipping-rates { destination_id, quantity } */
    public function rates(Request $request, string $publicId): JsonResponse
    {
        $product = LandingProduct::query()->where('public_id', $publicId)->first();
        if (!$product || !$product->isPurchasable()) {
            return $this->fail('Produk tidak ditemukan', ['code' => 'PRODUCT_NOT_FOUND'], 404);
        }
        $validated = $request->validate([
            'destination_id' => ['required', 'string', 'max:40'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        try {
            $rates = $this->shipping->ratesFor($product, (int) ($validated['quantity'] ?? 1), $validated['destination_id']);
        } catch (ShippingException $e) {
            return $this->fail($e->getMessage(), ['code' => 'SHIPPING_UNAVAILABLE'], 503);
        }

        return $this->ok([
            'items' => array_map(fn (ShippingRate $rate) => $rate->toArray(), $rates),
            'empty_message' => $rates === [] ? 'Belum ada kurir yang melayani tujuan ini. Coba kecamatan lain atau hubungi penjual.' : null,
        ], 'Ongkos kirim');
    }

    /** GET /apps/landing-builder/shipping — the shop's setup (seller). */
    public function show(Request $request, ShippingSettings $settings): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }

        return $this->ok($this->shopPayload($organization, $settings), 'Pengaturan pengiriman');
    }

    /** PUT /apps/landing-builder/shipping { origin_id, origin_label, couriers[] } */
    public function update(Request $request, ShippingSettings $settings): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $platform = $settings->all()['couriers'];
        $validated = $request->validate([
            'origin_id' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'origin_label' => ['required', 'string', 'max:255'],
            'couriers' => ['required', 'array', 'min:1'],
            'couriers.*' => ['string', 'in:' . implode(',', $platform ?: array_keys(ShippingSettings::COURIERS))],
        ], [
            'origin_id.required' => 'Pilih kecamatan asal pengiriman dari daftar.',
            'couriers.required' => 'Pilih minimal satu kurir.',
            'couriers.min' => 'Pilih minimal satu kurir.',
        ]);
        $organization->forceFill(['landing_shipping' => [
            'origin' => ['id' => $validated['origin_id'], 'label' => trim($validated['origin_label'])],
            'couriers' => array_values(array_unique($validated['couriers'])),
        ]])->save();

        return $this->ok($this->shopPayload($organization->fresh(), $settings), 'Pengaturan pengiriman disimpan');
    }

    private function shopPayload(Organization $organization, ShippingSettings $settings): array
    {
        $setup = $this->shipping->shopSetup($organization);
        $platform = $settings->all()['couriers'];

        return [
            'enabled' => $this->shipping->enabled(),
            'origin' => $setup['origin'] ?? null,
            'couriers' => $setup['couriers'] ?? $platform,
            'available_couriers' => array_map(fn (string $code) => ['code' => $code, 'name' => ShippingSettings::COURIERS[$code]], $platform),
        ];
    }
}
