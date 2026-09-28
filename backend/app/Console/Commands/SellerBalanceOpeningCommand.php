<?php

namespace App\Console\Commands;

use App\Models\LandingPageOrder;
use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;
use App\Models\SellerLedgerEntry;
use App\Services\SellerFinance\SellerLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time move of landing-page sales that were credited to the old (mixed) organization
 * wallet into the seller sales balance (owner decision Q2). Report only by default;
 * --force writes: an "opening" ledger row per seller and the same amount taken out of
 * the organization wallet, in one transaction. Nothing is moved when the wallet no
 * longer holds that money (already spent/withdrawn) — those sellers are listed for a
 * manual decision. Also lists mock top-ups (Q4) that must be cleaned before payouts open.
 */
class SellerBalanceOpeningCommand extends Command
{
    protected $signature = 'seller-balance:opening {--force : write the moves}';

    protected $description = 'Report (or move with --force) old landing sales from organization wallets into seller balances';

    public function handle(SellerLedger $ledger): int
    {
        $sales = LandingPageOrder::query()
            ->whereIn('status', [LandingPageOrder::STATUS_PAID, LandingPageOrder::STATUS_FULFILLED])
            ->whereNull('ledger_posted_at')
            ->selectRaw('organization_id, COUNT(*) AS orders, SUM(net_amount) AS net')
            ->groupBy('organization_id')
            ->get();

        $rows = [];
        foreach ($sales as $row) {
            $wallet = OrganizationWallet::query()->where('organization_id', $row->organization_id)->first();
            $available = (int) ($wallet->available_balance ?? 0) + (int) ($wallet->pending_balance ?? 0);
            $net = (int) $row->net;
            $action = $available >= $net ? 'move' : 'manual (wallet holds only Rp' . number_format($available, 0, ',', '.') . ')';
            $rows[] = [$row->organization_id, (int) $row->orders, $net, (int) ($wallet->available_balance ?? 0), (int) ($wallet->pending_balance ?? 0), $action];

            if ($this->option('force') && $action === 'move' && $net > 0) {
                DB::transaction(function () use ($row, $net, $ledger): void {
                    $wallet = OrganizationWallet::query()->where('organization_id', $row->organization_id)->lockForUpdate()->firstOrFail();
                    $fromPending = min($net, (int) $wallet->pending_balance);
                    $fromAvailable = $net - $fromPending;
                    $wallet->forceFill([
                        'pending_balance' => (int) $wallet->pending_balance - $fromPending,
                        'available_balance' => (int) $wallet->available_balance - $fromAvailable,
                    ])->save();
                    OrganizationWalletTransaction::query()->create([
                        'organization_id' => $row->organization_id, 'wallet_id' => $wallet->id, 'type' => 'transfer_to_seller_balance', 'direction' => 'debit',
                        'amount' => $net, 'balance_after' => (int) $wallet->available_balance, 'reference_type' => 'seller_balance_ledger',
                        'reference_id' => 'opening:' . $row->organization_id, 'description' => 'Pindah hasil penjualan ke Saldo Penjualan',
                    ]);

                    $balance = $ledger->lock((int) $row->organization_id);
                    if ($fromPending > 0) {
                        $ledger->post($balance, SellerLedgerEntry::TYPE_OPENING, SellerLedgerEntry::BUCKET_PENDING, $fromPending, [
                            'idempotency_key' => "opening:pending:{$row->organization_id}", 'available_at' => now(),
                            'description' => 'Saldo awal dari dompet lama (tertahan)',
                        ]);
                    }
                    if ($fromAvailable > 0) {
                        $ledger->post($balance, SellerLedgerEntry::TYPE_OPENING, SellerLedgerEntry::BUCKET_AVAILABLE, $fromAvailable, [
                            'idempotency_key' => "opening:available:{$row->organization_id}", 'description' => 'Saldo awal dari dompet lama',
                        ]);
                    }
                    LandingPageOrder::query()->where('organization_id', $row->organization_id)
                        ->whereIn('status', [LandingPageOrder::STATUS_PAID, LandingPageOrder::STATUS_FULFILLED])
                        ->whereNull('ledger_posted_at')->update(['ledger_posted_at' => now()]);
                });
            }
        }

        $this->info('Old landing sales still in organization wallets:');
        $rows === [] ? $this->line('  none') : $this->table(['organization_id', 'orders', 'net to move', 'wallet available', 'wallet pending', 'action'], $rows);

        $mock = OrganizationWalletTransaction::query()->where('type', 'wallet_topup_mock')
            ->selectRaw('organization_id, COUNT(*) AS n, SUM(amount) AS total')->groupBy('organization_id')->get();
        $this->info('Mock top-ups (test money, audit Q4) — not withdrawable, should be cleaned before launch:');
        $mock->isEmpty() ? $this->line('  none') : $this->table(['organization_id', 'count', 'total'], $mock->map(fn ($m) => [$m->organization_id, $m->n, $m->total])->all());

        if (!$this->option('force') && $rows !== []) {
            $this->comment('Report only. Run with --force to move the "move" rows.');
        }

        return self::SUCCESS;
    }
}
