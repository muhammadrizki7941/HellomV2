<?php

namespace App\Services\SellerFinance;

/**
 * Money split of one paid sale (owner decision Q7): the buyer pays the price, Hellom
 * pays the payment gateway, and Hellom's service fee is taken from the sale. The fee is
 * never lower than the gateway fee of that transaction (+ optional margin), so Hellom
 * never makes a loss on a sale.
 *
 *   gateway_fee  = fee reported by the gateway, else the admin estimate for the method
 *   platform_fee = max(price × fee% + flat fee, gateway_fee + min margin), capped at the price
 *   seller_net   = price − platform_fee
 *   hellom_net   = platform_fee − gateway_fee   (≥ min margin)
 */
final class FeeCalculator
{
    public function __construct(private readonly FinanceSettings $settings)
    {
    }

    /** Map a gateway method/channel name to a fee group. */
    public static function methodGroup(?string $method): string
    {
        $m = strtolower(trim((string) $method));

        return match (true) {
            $m === '' => 'other',
            str_contains($m, 'qris') => 'qris',
            str_contains($m, 'va') || str_contains($m, 'bank') || str_contains($m, 'transfer') => 'va',
            (bool) preg_match('/ovo|dana|shopee|gopay|linkaja|ewallet|e-wallet|astrapay|jenius/', $m) => 'ewallet',
            str_contains($m, 'cc') || str_contains($m, 'credit') || str_contains($m, 'card') => 'cc',
            (bool) preg_match('/alfamart|indomaret|cstore|retail/', $m) => 'retail',
            default => 'other',
        };
    }

    public function estimateGatewayFee(int $gross, ?string $method): int
    {
        $rule = (array) ($this->settings->get('gateway_fees')[self::methodGroup($method)] ?? ['percent' => 0, 'flat' => 0]);

        return (int) ceil($gross * (float) ($rule['percent'] ?? 0) / 100) + (int) ($rule['flat'] ?? 0);
    }

    /**
     * @return array{gross:int, gateway_fee:int, gateway_fee_source:string, platform_fee:int, seller_net:int, hellom_net:int, method_group:string}
     */
    public function split(int $gross, ?string $method, ?int $actualGatewayFee = null, ?int $feeBase = null): array
    {
        $gross = max(0, $gross);
        $settings = $this->settings->all();
        // The service fee is a share of the product price only: shipping goes to the seller in full
        // (owner decision, Fase 3). The gateway fee is on everything the buyer pays (Hellom pays it).
        $base = $feeBase === null ? $gross : max(0, min($gross, $feeBase));

        $gatewayFee = $actualGatewayFee !== null && $actualGatewayFee >= 0 ? $actualGatewayFee : $this->estimateGatewayFee($gross, $method);
        $configured = (int) round($base * (float) $settings['platform_fee_percent'] / 100) + (int) $settings['platform_fee_flat'];
        $platformFee = min($gross, max($configured, $gatewayFee + (int) $settings['min_margin_flat']));

        return [
            'gross' => $gross,
            'gateway_fee' => $gatewayFee,
            'gateway_fee_source' => $actualGatewayFee !== null ? 'gateway' : 'estimate',
            'platform_fee' => $platformFee,
            'seller_net' => $gross - $platformFee,
            'hellom_net' => $platformFee - $gatewayFee,
            'method_group' => self::methodGroup($method),
        ];
    }
}
