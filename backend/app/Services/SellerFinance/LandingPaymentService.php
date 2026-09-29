<?php

namespace App\Services\SellerFinance;

use App\Jobs\SendLandingSaleEmails;
use App\Jobs\SendMetaPurchaseEvent;
use App\Models\LandingPageOrder;
use App\Models\PlatformFinanceLedger;
use App\Services\Landing\CheckoutService;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\PaymentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Payment lifecycle of landing-page orders. Nothing here trusts the browser or the
 * webhook body: every state change comes from the gateway's own status API
 * (PaymentGateway::getStatus), and a payment is only accepted when the provider
 * confirms status "paid", our reference and exactly the order amount.
 *
 * Outcomes (returned for logging): processed, duplicate, pending, failed, expired,
 * amount_mismatch, amount_unknown, reference_mismatch, provider_mismatch,
 * unknown_reference, illegal_transition, unverifiable.
 */
final class LandingPaymentService
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly FeeCalculator $fees,
        private readonly SellerLedger $ledger,
        private readonly CheckoutService $checkout,
    ) {
    }

    /** A verified webhook arrived for $reference: confirm with the gateway and apply. */
    public function handleNotification(string $provider, string $reference, ?string $transactionId = null): string
    {
        $order = LandingPageOrder::query()->where('reference_id', $reference)->first();
        if (!$order) {
            return 'unknown_reference';
        }
        if ($order->provider && $order->provider !== $provider) {
            return 'provider_mismatch';
        }

        // The transaction id is only stored once the gateway has confirmed the payment (settle).
        return $this->reconcileOrder($order, 'webhook', $transactionId);
    }

    /** Ask the gateway about one order and apply the answer. */
    public function reconcileOrder(LandingPageOrder $order, string $source = 'reconcile', ?string $transactionId = null): string
    {
        $provider = (string) ($order->provider ?: $this->gateways->active()->name());
        // A transaction id from a verified webhook or from our own charge is trusted; one
        // the browser brought back (return URL hint) is not.
        $trusted = (bool) ($transactionId ?: $order->gateway_trx_id);
        $trx = $transactionId ?: $order->gateway_trx_id ?: data_get($order->metadata, 'trx_hint');
        $status = $this->gateways->get($provider)->getStatus((string) $order->reference_id, $order->gateway_ref, $trx ? (string) $trx : null);

        return match ($status->state) {
            PaymentStatus::PAID => $this->settle($order, $status, $source, $trusted),
            PaymentStatus::FAILED => $this->markFailed($order) ? 'failed' : 'pending',
            PaymentStatus::EXPIRED => $this->markExpired($order) ? 'expired' : 'pending',
            PaymentStatus::UNKNOWN => 'unverifiable',
            default => 'pending',
        };
    }

    /** Book a confirmed payment exactly once. */
    public function settle(LandingPageOrder $order, PaymentStatus $status, string $source, bool $trustedTransaction = true): string
    {
        if ($status->state !== PaymentStatus::PAID) {
            return 'pending';
        }
        if ($status->reference === null && !$trustedTransaction) {
            $this->flag($order, 'reference_unknown', ['transaction_id' => $status->transactionId]);

            return 'reference_unknown';
        }
        // One gateway payment can pay for one order only.
        if ($status->transactionId && LandingPageOrder::query()->where('gateway_trx_id', $status->transactionId)->where('id', '!=', $order->id)->exists()) {
            $this->flag($order, 'transaction_reused', ['transaction_id' => $status->transactionId]);

            return 'transaction_reused';
        }
        if ($status->reference !== null && $status->reference !== (string) $order->reference_id) {
            $this->flag($order, 'reference_mismatch', ['gateway_reference' => $status->reference]);

            return 'reference_mismatch';
        }
        if ($status->amount === null) {
            return 'amount_unknown';
        }
        if ($status->amount !== (int) $order->amount) {
            $this->flag($order, 'amount_mismatch', ['gateway_amount' => $status->amount, 'order_amount' => (int) $order->amount]);

            return 'amount_mismatch';
        }

        $outcome = DB::transaction(function () use ($order, $status, $source): string {
            $locked = LandingPageOrder::query()->lockForUpdate()->find($order->id);
            if (!$locked) {
                return 'unknown_reference';
            }
            if ($locked->isPaid() || $locked->status === LandingPageOrder::STATUS_REFUNDED || $locked->ledger_posted_at !== null) {
                return 'duplicate';
            }
            if (!$locked->canTransitionTo(LandingPageOrder::STATUS_PAID)) {
                return 'illegal_transition';
            }

            $split = $this->fees->split((int) $locked->amount, $status->channel ?: $status->method, $status->fee);
            $holdDays = $this->ledger->holdDaysFor((int) $locked->organization_id);
            $availableAt = now()->addDays($holdDays);

            // Sold count; stock/coupon taken again when the order had expired first.
            $this->checkout->commitSale($locked);

            $meta = is_array($locked->metadata) ? $locked->metadata : [];
            $meta['fee_split'] = $split;
            $meta['paid_via'] = $source;

            $locked->forceFill([
                'status' => LandingPageOrder::STATUS_PAID,
                'paid_at' => now(),
                'paid_amount' => $status->amount,
                'payment_method' => $status->method ? Str::limit($status->method, 40, '') : null,
                'payment_channel' => $status->channel ? Str::limit($status->channel, 60, '') : null,
                'gateway_trx_id' => $locked->gateway_trx_id ?: $status->transactionId,
                'commission_amount' => $split['platform_fee'],
                'gateway_fee_amount' => $split['gateway_fee'],
                'net_amount' => $split['seller_net'],
                'download_token' => $locked->download_token ?: Str::random(48),
                'settlement_eta' => $availableAt,
                'ledger_posted_at' => now(),
                'metadata' => $meta,
            ])->save();

            $this->ledger->recordSale($locked, $split, $availableAt);

            if ($split['platform_fee'] > 0) {
                PlatformFinanceLedger::recordRevenue('landing_platform_fee', $split['platform_fee'], (int) $locked->organization_id,
                    'landing_page_orders', (int) $locked->id, 'Biaya layanan penjualan: ' . $locked->product_name);
            }
            if ($split['gateway_fee'] > 0) {
                PlatformFinanceLedger::recordExpense('landing_gateway_fee', $split['gateway_fee'], 'landing_page_orders', (int) $locked->id,
                    'Biaya gateway (' . $split['gateway_fee_source'] . '): ' . $locked->product_name);
            }

            return 'processed';
        }, 3);

        if ($outcome === 'processed') {
            SendLandingSaleEmails::dispatch((int) $order->id)->afterCommit();
            // Seller's Meta Conversions API (no-op when the seller has no pixel + token).
            SendMetaPurchaseEvent::dispatch((int) $order->id)->afterCommit();
        }

        return $outcome;
    }

    public function markFailed(LandingPageOrder $order): bool
    {
        return $this->moveFromPending($order, LandingPageOrder::STATUS_FAILED, 'failed_at');
    }

    public function markExpired(LandingPageOrder $order): bool
    {
        return $this->moveFromPending($order, LandingPageOrder::STATUS_EXPIRED, 'expired_at');
    }

    /**
     * The buyer came back from the gateway with its transaction id (iPaymu appends it to
     * the return URL). It is only stored as a hint; the reconcile job verifies it with the
     * gateway, which must report our reference for the payment to count.
     */
    public function recordReturnHint(LandingPageOrder $order, string $transactionId): bool
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '' || strlen($transactionId) > 120 || $order->status !== LandingPageOrder::STATUS_PENDING || $order->gateway_trx_id) {
            return false;
        }
        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (!empty($meta['trx_hint'])) {
            return false;
        }
        $meta['trx_hint'] = $transactionId;
        $order->forceFill(['metadata' => $meta])->save();

        return true;
    }

    /** Pending orders past their expiry: one last check with the gateway, then expire. */
    public function expireDue(int $limit = 200): array
    {
        $summary = ['expired' => 0, 'paid' => 0, 'errors' => 0];
        $orders = LandingPageOrder::query()
            ->where('status', LandingPageOrder::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($orders as $order) {
            try {
                $outcome = $this->reconcileOrder($order);
            } catch (\Throwable $e) {
                $summary['errors']++;
                Log::warning('Landing order expiry check failed', ['order' => $order->id, 'error' => $e->getMessage()]);
                $outcome = 'unverifiable';
            }
            if ($outcome === 'processed') {
                $summary['paid']++;
            } elseif (in_array($outcome, ['pending', 'unverifiable'], true) && $this->markExpired($order)) {
                $summary['expired']++;
            }
        }

        return $summary;
    }

    /** Orders still pending a few minutes after checkout: ask the gateway (lost webhooks). */
    public function reconcilePending(int $olderThanMinutes = 5, int $limit = 200): array
    {
        $summary = ['checked' => 0, 'paid' => 0, 'errors' => 0];
        $orders = LandingPageOrder::query()
            ->where('status', LandingPageOrder::STATUS_PENDING)
            ->where('created_at', '<=', now()->subMinutes($olderThanMinutes))
            ->where('created_at', '>=', now()->subHours(72))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($orders as $order) {
            $summary['checked']++;
            try {
                if ($this->reconcileOrder($order) === 'processed') {
                    $summary['paid']++;
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                Log::warning('Landing order reconcile failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        return $summary;
    }

    private function moveFromPending(LandingPageOrder $order, string $status, string $timestampColumn): bool
    {
        return DB::transaction(function () use ($order, $status, $timestampColumn): bool {
            $locked = LandingPageOrder::query()->lockForUpdate()->find($order->id);
            if (!$locked || $locked->status !== LandingPageOrder::STATUS_PENDING || !$locked->canTransitionTo($status)) {
                return false;
            }
            $locked->forceFill(['status' => $status, $timestampColumn => now()])->save();
            $this->checkout->releaseInventory($locked);

            return true;
        }, 3);
    }

    /** @param array<string, mixed> $details */
    private function flag(LandingPageOrder $order, string $problem, array $details): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['payment_problems'][] = ['problem' => $problem, 'at' => now()->toIso8601String()] + $details;
        $order->forceFill(['metadata' => $meta])->save();
        Log::warning('Landing payment rejected: ' . $problem, ['order' => $order->id] + $details);
    }
}
