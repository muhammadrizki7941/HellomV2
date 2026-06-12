<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentSetting;

class PaymentGateway
{
    public function __construct(
        private readonly MidtransQrisProvider $midtrans,
    ) {}

    public function configuredDynamicProvider(?string $provider): ?DynamicQrisProvider
    {
        $provider = (string) ($provider ?: '');

        if ($provider === 'midtrans') {
            return $this->midtrans;
        }

        return null;
    }

    public function createDynamicQris(Order $order, PaymentSetting $setting): array
    {
        $provider = $this->configuredDynamicProvider($setting->dynamic_provider);
        if (!$provider) {
            throw new \RuntimeException('Provider QRIS dinamis tidak didukung: '.(string) $setting->dynamic_provider);
        }

        if (!$provider->configured()) {
            throw new \RuntimeException('Provider QRIS dinamis belum dikonfigurasi (cek .env).');
        }

        return $provider->create($order);
    }
}
