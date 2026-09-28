<?php

namespace App\Services\Payments;

use App\Services\Hellom\PaymentGatewaySettingsService;
use App\Services\Payments\Gateways\DokuGateway;
use App\Services\Payments\Gateways\IpaymuGateway;
use App\Services\Payments\Gateways\XenditGateway;
use InvalidArgumentException;

/** Resolves gateways by name; the active one is chosen by the super admin. */
final class GatewayRegistry
{
    private const MAP = [
        'ipaymu' => IpaymuGateway::class,
        'xendit' => XenditGateway::class,
        'doku' => DokuGateway::class,
    ];

    public function get(string $name): PaymentGateway
    {
        $class = self::MAP[strtolower($name)] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("Unknown payment gateway: {$name}");
        }

        return app($class);
    }

    public function active(): PaymentGateway
    {
        return $this->get((string) app(PaymentGatewaySettingsService::class)->getRuntimeConfig()['active_provider']);
    }

    /** First ready gateway that can send money to sellers, if any. */
    public function disburser(): ?PaymentGateway
    {
        foreach (array_keys(self::MAP) as $name) {
            $gateway = $this->get($name);
            if ($gateway->supportsDisbursement()) {
                return $gateway;
            }
        }

        return null;
    }
}
