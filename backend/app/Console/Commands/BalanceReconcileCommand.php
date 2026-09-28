<?php

namespace App\Console\Commands;

use App\Models\SellerBalance;
use App\Services\SellerFinance\SellerLedger;
use Illuminate\Console\Command;

/** Rebuild seller balances from the ledger and report differences (read-only unless --fix). */
class BalanceReconcileCommand extends Command
{
    protected $signature = 'balance:reconcile {--organization= : one organization id} {--fix : write the ledger totals into the cache}';

    protected $description = 'Compare seller_balances with seller_balance_ledger + seller_withdrawals';

    public function handle(SellerLedger $ledger): int
    {
        $ids = $this->option('organization')
            ? collect([(int) $this->option('organization')])
            : SellerBalance::query()->pluck('organization_id');

        $rows = [];
        foreach ($ids as $id) {
            $result = $ledger->reconcile((int) $id, (bool) $this->option('fix'));
            if (!$result['ok']) {
                foreach (['pending', 'available', 'processing', 'withdrawn'] as $bucket) {
                    if ($result['cached'][$bucket] !== $result['computed'][$bucket]) {
                        $rows[] = [$id, $bucket, $result['cached'][$bucket], $result['computed'][$bucket], $result['computed'][$bucket] - $result['cached'][$bucket]];
                    }
                }
            }
        }

        $this->info('Sellers checked: ' . $ids->count());
        if ($rows === []) {
            $this->info('All balances match the ledger.');

            return self::SUCCESS;
        }
        $this->table(['organization_id', 'bucket', 'cache', 'ledger', 'difference'], $rows);
        $this->warn(count($rows) . ' difference(s) ' . ($this->option('fix') ? 'fixed.' : 'found. Re-run with --fix to rewrite the cache from the ledger.'));

        return $this->option('fix') ? self::SUCCESS : self::FAILURE;
    }
}
