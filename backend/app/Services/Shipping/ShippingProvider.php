<?php

namespace App\Services\Shipping;

/**
 * A shipping-rate service (RajaOngkir today). Locations are the provider's own ids; rates are
 * what the buyer pays the courier. Implementations throw ShippingException on any failure.
 */
interface ShippingProvider
{
    public function name(): string;

    /** @return list<ShippingDestination> */
    public function searchDestinations(string $query, int $limit = 10): array;

    /**
     * @param list<string> $couriers courier codes (jne, jnt, sicepat…)
     * @return list<ShippingRate>
     */
    public function rates(string $originId, string $destinationId, int $weightGrams, array $couriers): array;
}
