<?php

namespace App\Services\Shipping;

/** One courier service and its price for a parcel. key() identifies the buyer's choice. */
final class ShippingRate
{
    public function __construct(
        public readonly string $courierCode,
        public readonly string $courierName,
        public readonly string $service,
        public readonly ?string $description,
        public readonly int $cost,
        public readonly ?string $etd,
    ) {
    }

    public function key(): string
    {
        return strtolower($this->courierCode) . ':' . strtoupper($this->service);
    }

    /** How buyers know the couriers (codes otherwise upper-cased: JNE, TIKI, SAP…). */
    private const SHORT_NAMES = ['jnt' => 'J&T', 'sicepat' => 'SiCepat', 'anteraja' => 'AnterAja', 'ninja' => 'Ninja', 'lion' => 'Lion Parcel', 'ide' => 'IDexpress', 'wahana' => 'Wahana'];

    /** "JNE REG", "J&T EZ" — stored on the order as the courier. */
    public function label(): string
    {
        $code = strtolower($this->courierCode);

        return trim((self::SHORT_NAMES[$code] ?? strtoupper($code)) . ' ' . $this->service);
    }

    /** @return array{key:string,courier_code:string,courier_name:string,service:string,description:?string,cost:int,etd:?string,label:string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key(), 'courier_code' => $this->courierCode, 'courier_name' => $this->courierName, 'service' => $this->service,
            'description' => $this->description, 'cost' => $this->cost, 'etd' => $this->etd, 'label' => $this->label(),
        ];
    }

    /** @return array<string, mixed> for the cache */
    public function toCache(): array
    {
        return [$this->courierCode, $this->courierName, $this->service, $this->description, $this->cost, $this->etd];
    }

    public static function fromCache(array $row): self
    {
        return new self((string) $row[0], (string) $row[1], (string) $row[2], $row[3] !== null ? (string) $row[3] : null, (int) $row[4], $row[5] !== null ? (string) $row[5] : null);
    }
}
