<?php

namespace App\Console\Commands;

use App\Services\SellerFinance\LandingPaymentService;
use App\Services\SellerFinance\SellerLedger;
use App\Services\SellerFinance\WithdrawalService;
use Illuminate\Console\Command;

/**
 * Scheduled money jobs for landing-page sales (see routes/console.php):
 *   landing:orders reconcile   ask the gateway about pending orders (lost webhooks)
 *   landing:orders expire      expire pending orders past expires_at (after a last gateway check)
 *   landing:orders release     move sales whose hold ended from TERTAHAN to TERSEDIA
 *   landing:orders sla         warn super admins about withdrawals near the 1×24h promise
 */
class LandingOrdersCommand extends Command
{
    protected $signature = 'landing:orders {action : reconcile|expire|release|sla} {--limit=200}';

    protected $description = 'Landing sales: reconcile/expire orders, release balances, withdrawal SLA warnings';

    public function handle(LandingPaymentService $payments, SellerLedger $ledger, WithdrawalService $withdrawals): int
    {
        $limit = max(1, min(2000, (int) $this->option('limit')));

        match ($this->argument('action')) {
            'reconcile' => $this->report('Reconcile', $payments->reconcilePending(5, $limit)),
            'expire' => $this->report('Expire', $payments->expireDue($limit)),
            'release' => $this->report('Release', ['released_orders' => $ledger->releaseDue($limit)]),
            'sla' => $this->report('SLA', ['warned' => $withdrawals->slaCheck()]),
            default => $this->error('Action must be reconcile, expire, release or sla.'),
        };

        return self::SUCCESS;
    }

    /** @param array<string, int> $summary */
    private function report(string $title, array $summary): void
    {
        $this->info($title . ': ' . collect($summary)->map(fn ($v, $k) => "{$k}={$v}")->implode(', '));
    }
}
