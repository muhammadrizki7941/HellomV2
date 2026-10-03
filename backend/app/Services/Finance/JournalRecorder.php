<?php

namespace App\Services\Finance;

use App\Models\CheckoutIntent;
use App\Models\FinanceJournalEntry;
use App\Models\LandingPageOrder;
use App\Models\LandingRefund;
use App\Models\OrganizationWalletTransaction;
use App\Models\ProductPurchase;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWithdrawal;
use App\Services\SellerFinance\FeeCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Turns business records into journal entries. Every method is idempotent (event key
 * derived from the source row), so live hooks, observers and finance:journal-backfill
 * can all call it for the same row.
 */
final class JournalRecorder
{
    /** Wallet debits that pay a subscription (revenue for Hellom). */
    public const WALLET_SUBSCRIPTION_DEBITS = ['app_checkout_debit', 'subscription_renew_debit', 'subscription_auto_renew_debit'];

    /** Wallet credits from a gateway payment (top-up). */
    public const WALLET_TOPUP_CREDITS = ['payment_credit', 'payment_credit_pending'];

    public function __construct(private readonly FinanceJournal $journal, private readonly FeeCalculator $fees)
    {
    }

    /** Run a recorder without ever breaking the money flow that called it. */
    public static function safely(callable $record): void
    {
        try {
            DB::transaction(fn () => $record(app(self::class)));
        } catch (\Throwable $e) {
            report($e); // finance:journal-backfill picks the row up later
        }
    }

    /** One seller ledger row → one entry (key seller_ledger:{id}). Release "out" rows are covered by their "in" row. */
    public function sellerLedger(SellerLedgerEntry $row): ?FinanceJournalEntry
    {
        $org = (int) $row->organization_id;
        $amount = (int) $row->amount;
        $bucket = (string) $row->bucket;
        $seller = FinanceJournal::sellerAccount($org, $bucket);
        $order = $row->order_id ? LandingPageOrder::query()->find($row->order_id) : null;
        $provider = $order?->provider ? strtolower((string) $order->provider) : null;

        $lines = match ((string) $row->type) {
            SellerLedgerEntry::TYPE_SALE => $this->saleLines($row, $seller, $provider),
            SellerLedgerEntry::TYPE_PLATFORM_FEE, SellerLedgerEntry::TYPE_GATEWAY_FEE => [$seller => -$amount, 'revenue:platform_fee' => $amount],
            SellerLedgerEntry::TYPE_RELEASE => $bucket === SellerLedgerEntry::BUCKET_AVAILABLE
                ? [FinanceJournal::sellerAccount($org, SellerLedgerEntry::BUCKET_PENDING) => $amount, $seller => -$amount]
                : null,
            SellerLedgerEntry::TYPE_WITHDRAWAL, SellerLedgerEntry::TYPE_WITHDRAWAL_REVERSAL => [$seller => -$amount, FinanceJournal::sellerAccount($org, 'processing') => $amount],
            SellerLedgerEntry::TYPE_REFUND, SellerLedgerEntry::TYPE_REFUND_REVERSAL => [$seller => -$amount, 'refund:payable' => $amount],
            SellerLedgerEntry::TYPE_OPENING => [$seller => -$amount, 'equity:opening' => $amount],
            default => [$seller => -$amount, 'hellom:adjustment' => $amount], // adjustment by super admin
        };
        if ($lines === null) {
            return null;
        }

        $isWithdrawal = in_array($row->type, [SellerLedgerEntry::TYPE_WITHDRAWAL, SellerLedgerEntry::TYPE_WITHDRAWAL_REVERSAL, SellerLedgerEntry::TYPE_ADJUSTMENT, SellerLedgerEntry::TYPE_OPENING], true);

        return $this->journal->post("seller_ledger:{$row->id}", [
            'event_type' => (string) $row->type,
            'source' => $isWithdrawal ? FinanceJournalEntry::SOURCE_SELLER_FINANCE : FinanceJournalEntry::SOURCE_LANDING,
            'source_type' => $row->order_id ? 'landing_page_orders' : ($row->withdrawal_id ? 'seller_withdrawals' : 'seller_balance_ledger'),
            'source_id' => (int) ($row->order_id ?: ($row->withdrawal_id ?: $row->id)),
            'provider' => $row->type === SellerLedgerEntry::TYPE_SALE ? ($provider ?: 'manual') : null,
            'organization_id' => $org,
            'amount' => abs($amount),
            'occurred_at' => $row->created_at,
            'description' => $row->description,
            'metadata' => ['seller_ledger_id' => (int) $row->id, 'bucket' => $bucket],
        ], $lines);
    }

    /** Withdrawal transferred: processing leaves the seller, net leaves Hellom's bank, fee is revenue. */
    public function withdrawalPaid(SellerWithdrawal $withdrawal): ?FinanceJournalEntry
    {
        if ($withdrawal->status !== SellerWithdrawal::STATUS_PAID) {
            return null;
        }
        $amount = (int) $withdrawal->amount;
        $fee = min($amount, max(0, (int) $withdrawal->fee_amount));
        $provider = $withdrawal->mode === 'auto' && $withdrawal->provider ? (string) $withdrawal->provider : 'manual';

        return $this->journal->post("withdrawal_paid:{$withdrawal->id}", [
            'event_type' => 'withdrawal_paid',
            'source' => FinanceJournalEntry::SOURCE_SELLER_FINANCE,
            'source_type' => 'seller_withdrawals',
            'source_id' => (int) $withdrawal->id,
            'provider' => $provider,
            'organization_id' => (int) $withdrawal->organization_id,
            'amount' => $amount,
            'occurred_at' => $withdrawal->paid_at ?? now(),
            'description' => 'Penarikan dana ' . $withdrawal->reference,
        ], [
            FinanceJournal::sellerAccount((int) $withdrawal->organization_id, 'processing') => $amount,
            FinanceJournal::cashAccount($provider) => -($amount - $fee),
            'revenue:withdrawal_fee' => -$fee,
        ]);
    }

    /** Refund transferred to the buyer by Hellom. */
    public function refundPaid(LandingRefund $refund): ?FinanceJournalEntry
    {
        if ($refund->status !== LandingRefund::STATUS_PAID) {
            return null;
        }
        $amount = (int) $refund->amount;

        return $this->journal->post("refund_paid:{$refund->id}", [
            'event_type' => 'refund_paid',
            'source' => FinanceJournalEntry::SOURCE_LANDING,
            'source_type' => 'landing_page_orders',
            'source_id' => (int) $refund->order_id,
            'provider' => 'manual',
            'organization_id' => (int) $refund->organization_id,
            'amount' => $amount,
            'occurred_at' => $refund->paid_at ?? now(),
            'description' => 'Refund ke pembeli',
        ], ['refund:payable' => $amount, 'bank:hellom' => -$amount]);
    }

    /** Hellom's own digital product: paid = revenue, refunded = reversed. */
    public function productPurchase(ProductPurchase $purchase): ?FinanceJournalEntry
    {
        $gross = (int) $purchase->amount_paid;
        $provider = strtolower((string) $purchase->payment_gateway) ?: 'manual';
        if ($gross <= 0 || $provider === 'free') {
            return null;
        }
        $entry = [
            'source' => FinanceJournalEntry::SOURCE_DIGITAL_PRODUCT,
            'source_type' => 'product_purchases',
            'source_id' => (int) $purchase->id,
            'provider' => $provider,
            'amount' => $gross,
            'metadata' => ['user_id' => (int) $purchase->user_id, 'product_id' => (int) $purchase->product_id, 'transaction_code' => $purchase->transaction_code],
        ];

        if ($purchase->payment_status === 'paid' || ($purchase->payment_status === 'refunded' && $purchase->paid_at)) {
            $fee = $this->gatewayFee($provider, $gross, $purchase->payment_method);
            $this->journal->post("product_purchase:{$purchase->id}:paid", $entry + [
                'event_type' => 'product_paid',
                'occurred_at' => $purchase->paid_at ?? $purchase->updated_at,
                'description' => 'Penjualan produk Hellom ' . $purchase->transaction_code,
            ], [FinanceJournal::cashAccount($provider) => $gross - $fee, 'expense:gateway_fee' => $fee, 'revenue:digital_product' => -$gross]);
        }
        if ($purchase->payment_status === 'refunded' && FinanceJournal::exists("product_purchase:{$purchase->id}:paid")) {
            return $this->journal->post("product_purchase:{$purchase->id}:refunded", $entry + [
                'event_type' => 'product_refunded',
                'occurred_at' => $purchase->updated_at ?? now(),
                'description' => 'Refund produk Hellom ' . $purchase->transaction_code,
            ], ['revenue:digital_product' => $gross, 'bank:hellom' => -$gross]);
        }

        return FinanceJournalEntry::query()->where('event_key', "product_purchase:{$purchase->id}:paid")->first();
    }

    /** Subscription / app checkout confirmed via gateway or manual transfer. Wallet payments come from walletTransaction(). */
    public function checkoutIntent(CheckoutIntent $intent): ?FinanceJournalEntry
    {
        $gross = (int) $intent->amount;
        $meta = is_array($intent->metadata) ? $intent->metadata : [];
        if ($intent->status !== 'confirmed' || $gross <= 0 || isset($meta['wallet_payment'])) {
            return null;
        }
        $provider = 'manual';
        foreach (FinanceJournal::GATEWAYS as $gateway) {
            if (isset($meta[$gateway])) {
                $provider = $gateway;
                break;
            }
        }
        $gatewayMeta = is_array($meta[$provider] ?? null) ? $meta[$provider] : [];
        $fee = $this->gatewayFee($provider, $gross, $gatewayMeta['channel'] ?? $gatewayMeta['method'] ?? null);

        return $this->journal->post("checkout_intent:{$intent->id}", [
            'event_type' => 'subscription_paid',
            'source' => FinanceJournalEntry::SOURCE_SUBSCRIPTION,
            'source_type' => 'checkout_intents',
            'source_id' => (int) $intent->id,
            'provider' => $provider,
            'organization_id' => $intent->organization_id ? (int) $intent->organization_id : null,
            'amount' => $gross,
            'occurred_at' => $intent->updated_at ?? now(),
            'description' => 'Langganan / aktivasi aplikasi',
            'metadata' => ['plan_id' => $intent->plan_id ? (int) $intent->plan_id : null, 'app_id' => $intent->app_id ? (int) $intent->app_id : null],
        ], [FinanceJournal::cashAccount($provider) => $gross - $fee, 'expense:gateway_fee' => $fee, 'revenue:subscription' => -$gross]);
    }

    /** Wallet top-up credits (gateway → wallet) and subscription debits (wallet → revenue). */
    public function walletTransaction(OrganizationWalletTransaction $tx): ?FinanceJournalEntry
    {
        $amount = (int) $tx->amount;
        $org = (int) $tx->organization_id;
        if ($amount <= 0) {
            return null;
        }
        if (in_array($tx->type, self::WALLET_TOPUP_CREDITS, true)) {
            $provider = match (true) {
                str_starts_with((string) $tx->reference_type, 'ipaymu') => 'ipaymu',
                str_starts_with((string) $tx->reference_type, 'xendit') => 'xendit',
                str_starts_with((string) $tx->reference_type, 'doku') => 'doku',
                default => 'manual',
            };
            $entry = ['event_type' => 'wallet_topup', 'source' => FinanceJournalEntry::SOURCE_WALLET_TOPUP, 'provider' => $provider, 'description' => 'Top-up saldo'];
            $lines = [FinanceJournal::cashAccount($provider) => $amount, "wallet:{$org}" => -$amount];
        } elseif (in_array($tx->type, self::WALLET_SUBSCRIPTION_DEBITS, true)) {
            $entry = ['event_type' => 'subscription_paid', 'source' => FinanceJournalEntry::SOURCE_SUBSCRIPTION, 'provider' => 'wallet', 'description' => 'Langganan dibayar dari saldo'];
            $lines = ["wallet:{$org}" => $amount, 'revenue:subscription' => -$amount];
        } elseif ($tx->type === 'transfer_to_seller_balance') {
            // seller-balance:opening: the matching "opening" seller row credits the seller against equity:opening.
            $entry = ['event_type' => 'wallet_to_seller_balance', 'source' => FinanceJournalEntry::SOURCE_SELLER_FINANCE, 'provider' => 'wallet', 'description' => 'Pindah ke Saldo Penjualan'];
            $lines = ["wallet:{$org}" => $amount, 'equity:opening' => -$amount];
        } else {
            return null; // internal wallet moves (settle release, legacy withdrawals) are not money in/out for Hellom
        }

        return $this->journal->post("wallet_tx:{$tx->id}", $entry + [
            'source_type' => 'organization_wallet_transactions',
            'source_id' => (int) $tx->id,
            'organization_id' => $org,
            'amount' => $amount,
            'occurred_at' => $tx->created_at ?? now(),
            'metadata' => ['type' => (string) $tx->type, 'reference_type' => $tx->reference_type, 'reference_id' => $tx->reference_id],
        ], $lines);
    }

    /** Estimated gateway fee (manual transfers have none). */
    private function gatewayFee(string $provider, int $gross, ?string $method): int
    {
        if (!in_array($provider, FinanceJournal::GATEWAYS, true)) {
            return 0;
        }

        return min($gross, max(0, $this->fees->estimateGatewayFee($gross, $method)));
    }

    /** @return array<string,int> */
    private function saleLines(SellerLedgerEntry $row, string $seller, ?string $provider): array
    {
        $gross = (int) $row->amount;
        $fee = min($gross, max(0, (int) (($row->metadata ?? [])['gateway_fee'] ?? 0)));

        return [FinanceJournal::cashAccount($provider) => $gross - $fee, 'expense:gateway_fee' => $fee, $seller => -$gross];
    }
}
