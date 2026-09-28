<?php

namespace App\Console\Commands;

use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Takes test money from mock top-ups (`wallet_topup_mock`, billing mock endpoint) back out
 * of organization wallets (owner decision after audit Q4/Q5). Nothing is deleted: each
 * cleaned wallet gets one `wallet_topup_mock_reversal` debit, so the history still adds up.
 * Idempotent — only the part not yet reversed is taken. A wallet never goes below zero:
 * mock money that was already spent (e.g. on a subscription) is reported as a shortfall.
 * Report only by default; --force writes.
 */
class CleanMockTopupsCommand extends Command
{
    public const REVERSAL_TYPE = 'wallet_topup_mock_reversal';

    protected $signature = 'wallet:clean-mock-topups {--organization= : only this organization id} {--force : write the reversals}';

    protected $description = 'Report (or reverse with --force) mock top-up money still in organization wallets';

    public function handle(): int
    {
        $mock = OrganizationWalletTransaction::query()
            ->where('type', 'wallet_topup_mock')
            ->when($this->option('organization'), fn ($q, $id) => $q->where('organization_id', (int) $id))
            ->selectRaw('organization_id, COUNT(*) AS n, SUM(amount) AS total')
            ->groupBy('organization_id')
            ->orderBy('organization_id')
            ->get();

        if ($mock->isEmpty()) {
            $this->info('No mock top-ups found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($mock as $row) {
            $orgId = (int) $row->organization_id;
            $result = DB::transaction(function () use ($orgId, $row): array {
                $wallet = OrganizationWallet::query()->where('organization_id', $orgId)->lockForUpdate()->first();
                $reversed = (int) OrganizationWalletTransaction::query()
                    ->where('organization_id', $orgId)->where('type', self::REVERSAL_TYPE)->sum('amount');
                $outstanding = max(0, (int) $row->total - $reversed);
                $available = (int) ($wallet->available_balance ?? 0);
                $take = min($outstanding, $available);

                if ($this->option('force') && $wallet && $take > 0) {
                    $wallet->forceFill([
                        'available_balance' => $available - $take,
                        'total_out' => (int) $wallet->total_out + $take,
                    ])->save();
                    OrganizationWalletTransaction::query()->create([
                        'organization_id' => $orgId,
                        'wallet_id' => (int) $wallet->id,
                        'type' => self::REVERSAL_TYPE,
                        'direction' => 'debit',
                        'amount' => $take,
                        'balance_after' => (int) $wallet->available_balance,
                        'reference_type' => 'mock_cleanup',
                        'reference_id' => 'mock-cleanup:' . $orgId . ':' . ($reversed + $take),
                        'description' => 'Pembersihan saldo uji coba (top-up mock)',
                        'metadata' => ['mock_total' => (int) $row->total, 'previously_reversed' => $reversed],
                    ]);
                }

                return [$orgId, (int) $row->n, (int) $row->total, $reversed, $available, $take, $outstanding - $take];
            }, 3);
            $rows[] = $result;
        }

        $this->table(['organization_id', 'mock count', 'mock total', 'already reversed', 'wallet available', $this->option('force') ? 'reversed now' : 'to reverse', 'shortfall (spent)'], $rows);
        if (!$this->option('force')) {
            $this->comment('Report only. Run with --force to write the reversals.');
        }
        if (collect($rows)->sum(fn ($r) => $r[6]) > 0) {
            $this->warn('Shortfall = mock money already spent (e.g. on subscriptions). Check those subscriptions by hand.');
        }

        return self::SUCCESS;
    }
}
