<?php

namespace App\Services\SellerFinance;

use App\Models\LandingPageOrder;
use App\Models\Organization;
use App\Models\SellerBalance;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWithdrawal;
use App\Services\Finance\JournalRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of a seller's sales balance.
 *
 * Ledger rows (append-only) move money in two buckets:
 *   pending   (TERTAHAN)  sale +gross, platform_fee −fee, until the hold ends
 *   available (TERSEDIA)  release pair moves the net from pending to available;
 *                         withdrawal −amount on request, withdrawal_reversal +amount on fail/cancel
 * processing / withdrawn (DITARIK) come from seller_withdrawals.
 * seller_balances is a cache updated in the same transaction under a row lock; callers
 * must already be inside DB::transaction().
 */
final class SellerLedger
{
    public function __construct(private readonly FinanceSettings $settings)
    {
    }

    /** Lock (creating if needed) the seller's balance row. Call inside a transaction. */
    public function lock(int $organizationId): SellerBalance
    {
        // Lock the existing row first: an INSERT IGNORE on an existing key takes a shared
        // lock and two concurrent requests would then deadlock on FOR UPDATE.
        $existing = SellerBalance::query()->whereKey($organizationId)->lockForUpdate()->first();
        if ($existing) {
            return $existing;
        }
        SellerBalance::query()->insertOrIgnore([
            'organization_id' => $organizationId, 'pending' => 0, 'available' => 0, 'processing' => 0, 'withdrawn' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return SellerBalance::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Append one row and update the cache. Returns null when the idempotency key was used already.
     *
     * @param array<string, mixed> $attributes order_id, withdrawal_id, group_key, idempotency_key, available_at, description, metadata, created_by_user_id
     */
    public function post(SellerBalance $balance, string $type, string $bucket, int $amount, array $attributes = []): ?SellerLedgerEntry
    {
        if (!in_array($bucket, [SellerLedgerEntry::BUCKET_PENDING, SellerLedgerEntry::BUCKET_AVAILABLE], true)) {
            throw new RuntimeException("Unknown bucket {$bucket}");
        }
        $key = $attributes['idempotency_key'] ?? null;
        if ($key !== null && SellerLedgerEntry::query()->where('idempotency_key', $key)->exists()) {
            return null;
        }

        if ($bucket === SellerLedgerEntry::BUCKET_PENDING) {
            $balance->pending += $amount;
        } else {
            $balance->available += $amount;
        }
        $balance->save();

        $entry = SellerLedgerEntry::query()->create(array_merge($attributes, [
            'organization_id' => (int) $balance->organization_id,
            'type' => $type,
            'bucket' => $bucket,
            'amount' => $amount,
            'pending_after' => (int) $balance->pending,
            'available_after' => (int) $balance->available,
        ]));
        // Mirror into the double-entry journal; never breaks the seller's money flow.
        JournalRecorder::safely(fn (JournalRecorder $journal) => $journal->sellerLedger($entry));

        return $entry;
    }

    /** Days a sale stays TERTAHAN for this seller. */
    public function holdDaysFor(int $organizationId): int
    {
        $override = SellerBalance::query()->whereKey($organizationId)->value('hold_days_override');
        if ($override !== null) {
            return (int) $override;
        }
        $settings = $this->settings->all();
        $createdAt = Organization::query()->whereKey($organizationId)->value('created_at');
        $isNew = $createdAt !== null && now()->diffInDays($createdAt, true) < (int) $settings['new_seller_days'];

        return $isNew ? max((int) $settings['hold_days'], (int) $settings['new_seller_hold_days']) : (int) $settings['hold_days'];
    }

    /**
     * Book a paid sale: +gross and −platform fee in pending, released later (or now, when
     * the hold is 0). Idempotent per order.
     *
     * @param array{gross:int, platform_fee:int, gateway_fee:int, seller_net:int} $split
     */
    public function recordSale(LandingPageOrder $order, array $split, CarbonInterface $availableAt): void
    {
        $balance = $this->lock((int) $order->organization_id);
        $meta = ['gateway_fee' => $split['gateway_fee'], 'seller_net' => $split['seller_net']];

        $sale = $this->post($balance, SellerLedgerEntry::TYPE_SALE, SellerLedgerEntry::BUCKET_PENDING, $split['gross'], [
            'order_id' => $order->id,
            'idempotency_key' => "sale:order:{$order->id}",
            'available_at' => $availableAt,
            'description' => 'Penjualan: ' . $order->product_name,
            'metadata' => $meta,
        ]);
        if ($sale === null) {
            return;
        }
        if ($split['platform_fee'] > 0) {
            $this->post($balance, SellerLedgerEntry::TYPE_PLATFORM_FEE, SellerLedgerEntry::BUCKET_PENDING, -$split['platform_fee'], [
                'order_id' => $order->id,
                'idempotency_key' => "platform_fee:order:{$order->id}",
                'description' => 'Biaya layanan Hellom (termasuk biaya pembayaran)',
                'metadata' => $meta,
            ]);
        }

        if ($availableAt->lessThanOrEqualTo(now())) {
            $this->releaseOrder($balance, $order);
        }
    }

    /** Move an order's net from pending to available (idempotent). */
    public function releaseOrder(SellerBalance $balance, LandingPageOrder $order): bool
    {
        $net = (int) SellerLedgerEntry::query()
            ->where('order_id', $order->id)
            ->where('bucket', SellerLedgerEntry::BUCKET_PENDING)
            ->sum('amount');
        if ($net <= 0) {
            return false;
        }
        $group = "release:order:{$order->id}";
        $out = $this->post($balance, SellerLedgerEntry::TYPE_RELEASE, SellerLedgerEntry::BUCKET_PENDING, -$net, [
            'order_id' => $order->id, 'group_key' => $group, 'idempotency_key' => "{$group}:out", 'description' => 'Saldo cair: ' . $order->product_name,
        ]);
        if ($out === null) {
            return false;
        }
        $this->post($balance, SellerLedgerEntry::TYPE_RELEASE, SellerLedgerEntry::BUCKET_AVAILABLE, $net, [
            'order_id' => $order->id, 'group_key' => $group, 'idempotency_key' => "{$group}:in", 'description' => 'Saldo cair: ' . $order->product_name,
        ]);

        return true;
    }

    /** Release every sale whose hold has ended (scheduled). Returns orders released. */
    public function releaseDue(int $limit = 500): int
    {
        $orderIds = SellerLedgerEntry::query()
            ->where('type', SellerLedgerEntry::TYPE_SALE)
            ->where('available_at', '<=', now())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('seller_balance_ledger as r')
                ->whereColumn('r.order_id', 'seller_balance_ledger.order_id')
                ->where('r.type', SellerLedgerEntry::TYPE_RELEASE))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('order_id');

        $released = 0;
        foreach ($orderIds as $orderId) {
            $released += (int) DB::transaction(function () use ($orderId): bool {
                $order = LandingPageOrder::query()->find($orderId);
                if (!$order || $order->status === LandingPageOrder::STATUS_REFUNDED) {
                    return false;
                }
                $balance = $this->lock((int) $order->organization_id);

                return $this->releaseOrder($balance, $order);
            }, 3);
        }

        return $released;
    }

    /**
     * Totals rebuilt from the ledger and withdrawals.
     *
     * @return array{pending:int, available:int, processing:int, withdrawn:int}
     */
    public function computed(int $organizationId): array
    {
        $sums = SellerLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->selectRaw('bucket, SUM(amount) AS total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return [
            'pending' => (int) ($sums[SellerLedgerEntry::BUCKET_PENDING] ?? 0),
            'available' => (int) ($sums[SellerLedgerEntry::BUCKET_AVAILABLE] ?? 0),
            'processing' => (int) SellerWithdrawal::query()->where('organization_id', $organizationId)->whereIn('status', SellerWithdrawal::OPEN_STATUSES)->sum('amount'),
            'withdrawn' => (int) SellerWithdrawal::query()->where('organization_id', $organizationId)->where('status', SellerWithdrawal::STATUS_PAID)->sum('amount'),
        ];
    }

    /**
     * Compare cache with ledger; with $fix the cache is rewritten from the ledger.
     *
     * @return array{organization_id:int, cached:array, computed:array, ok:bool}
     */
    public function reconcile(int $organizationId, bool $fix = false): array
    {
        return DB::transaction(function () use ($organizationId, $fix): array {
            $balance = $this->lock($organizationId);
            $cached = ['pending' => (int) $balance->pending, 'available' => (int) $balance->available, 'processing' => (int) $balance->processing, 'withdrawn' => (int) $balance->withdrawn];
            $computed = $this->computed($organizationId);
            $ok = $cached === $computed;
            if (!$ok && $fix) {
                $balance->forceFill($computed)->save();
            }

            return ['organization_id' => $organizationId, 'cached' => $cached, 'computed' => $computed, 'ok' => $ok];
        }, 3);
    }
}
