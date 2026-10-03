<?php

namespace App\Services\Payments;

final class ChargeRequest
{
    /**
     * @param array<string, scalar> $notifyContext query parameters added to the notification URL
     * @param string|null $preferredMethod buyer's choice from PaymentGateway::paymentOptions()
     *                                     (a direct channel such as "qris"/"bca", or "other" for the hosted page)
     * @param string|null $cancelUrl where the hosted page sends a buyer who cancels (default: returnUrl)
     * @param string|null $description shown on the provider's payment page (default: "Pembelian: {product}")
     */
    public function __construct(
        public readonly string $reference,
        public readonly int $amount,
        public readonly string $productName,
        public readonly string $buyerName,
        public readonly string $buyerEmail,
        public readonly ?string $buyerPhone,
        public readonly string $returnUrl,
        public readonly array $notifyContext = [],
        public readonly ?string $preferredMethod = null,
        public readonly ?string $cancelUrl = null,
        public readonly ?string $description = null,
    ) {
    }
}
