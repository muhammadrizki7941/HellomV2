<?php

namespace App\Services\Shipping;

/** A place a parcel can be sent from / to (sub-district level), as the provider knows it. */
final class ShippingDestination
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $city = null,
        public readonly ?string $province = null,
        public readonly ?string $postalCode = null,
    ) {
    }

    /** @return array{id:string,label:string,city:?string,province:?string,postal_code:?string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'city' => $this->city, 'province' => $this->province, 'postal_code' => $this->postalCode];
    }
}
