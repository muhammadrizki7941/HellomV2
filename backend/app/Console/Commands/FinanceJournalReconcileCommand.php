<?php

namespace App\Console\Commands;

use App\Models\FinanceJournalLine;
use App\Models\SellerBalance;
use App\Services\Finance\FinanceJournal;
use App\Services\SellerFinance\SellerLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Checks the journal: every entry sums to zero, and each seller's journal balance
 * (pending / available / processing) equals the seller ledger. Read only; exit code 1
 * when something does not match (fix with finance:journal-backfill, never by editing rows).
 */
class FinanceJournalReconcileCommand extends Command
{
    protected $signature = 'finance:journal-reconcile {--organization= : only this organization}';

    protected $description = 'Verify the finance journal against itself and the seller ledger';

    public function handle(FinanceJournal $journal, SellerLedger $ledger): int
    {
        $problems = 0;

        $unbalanced = FinanceJournalLine::query()
            ->select('entry_id', DB::raw('SUM(amount) AS total'))
            ->groupBy('entry_id')
            ->havingRaw('SUM(amount) <> 0')
            ->limit(50)
            ->pluck('total', 'entry_id');
        foreach ($unbalanced as $entryId => $total) {
            $problems++;
            $this->error("Entri #{$entryId} tidak seimbang ({$total}).");
        }

        // Sellers known to the ledger cache or to the journal (a journal-only seller is a mismatch too).
        $organizations = SellerBalance::query()
            ->when($this->option('organization'), fn ($q, $org) => $q->whereKey((int) $org))
            ->pluck('organization_id')
            ->merge(FinanceJournalLine::query()->where('account', 'like', 'seller:%')
                ->when($this->option('organization'), fn ($q, $org) => $q->where('organization_id', (int) $org))
                ->distinct()->pluck('organization_id'))
            ->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $rows = [];
        foreach ($organizations as $orgId) {
            $orgId = (int) $orgId;
            $balances = $journal->balances("seller:{$orgId}:");
            $fromJournal = [
                'pending' => -($balances[FinanceJournal::sellerAccount($orgId, 'pending')] ?? 0),
                'available' => -($balances[FinanceJournal::sellerAccount($orgId, 'available')] ?? 0),
                'processing' => -($balances[FinanceJournal::sellerAccount($orgId, 'processing')] ?? 0),
            ];
            $computed = $ledger->computed($orgId);
            $expected = ['pending' => $computed['pending'], 'available' => $computed['available'], 'processing' => $computed['processing']];
            if ($fromJournal !== $expected) {
                $problems++;
                $rows[] = [$orgId, json_encode($expected), json_encode($fromJournal)];
            }
        }
        if ($rows !== []) {
            $this->table(['organisasi', 'ledger penjual', 'jurnal'], $rows);
        }

        $this->info(count($organizations) . ' saldo penjual dicek, ' . $problems . ' selisih.');

        return $problems > 0 ? self::FAILURE : self::SUCCESS;
    }
}
