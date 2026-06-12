<?php

namespace App\Services\Payments;

use App\Models\Order;

interface DynamicQrisProvider
{
    /**
     * Create a dynamic QRIS charge for an order.
     *
     * Must return keys:
     * - reference (string)
     * - qr_url (string|null)
     * - qr_string (string|null)
     * - meta (array)
     */
    public function create(Order $order): array;

    /**
     * Whether the provider is configured (env keys present).
     */
    public function configured(): bool;

    public function name(): string;
}
