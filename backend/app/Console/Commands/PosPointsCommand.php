<?php

namespace App\Console\Commands;

use App\Models\MemberPointTransaction;
use App\Models\PosMember;
use App\Services\Pos\LoyaltyService;
use Illuminate\Console\Command;

/**
 * pos:points reconcile  — compare every member's cached balance with the ledger sum
 *                          (read-only; --fix rewrites the cache from the ledger).
 * pos:points expire     — expire point lots past their date (scheduled daily).
 */
class PosPointsCommand extends Command
{
    protected $signature = 'pos:points {action : reconcile|expire} {--fix : reconcile: write the ledger balance into the member cache} {--organization= : limit to one organization id}';

    protected $description = 'Member points ledger: reconcile balances or expire old points';

    public function handle(LoyaltyService $loyalty): int
    {
        return match ($this->argument('action')) {
            'reconcile' => $this->reconcile($loyalty),
            'expire' => $this->expire($loyalty),
            default => $this->invalid(),
        };
    }

    private function reconcile(LoyaltyService $loyalty): int
    {
        $fix = (bool) $this->option('fix');
        $mismatches = [];
        $checked = 0;

        PosMember::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->where('organization_id', (int) $id))
            ->orderBy('id')
            ->chunkById(500, function ($members) use ($loyalty, $fix, &$mismatches, &$checked) {
                foreach ($members as $member) {
                    $checked++;
                    $result = $loyalty->reconcile($member, $fix);
                    if (!$result['ok']) {
                        $mismatches[] = [$member->id, $member->organization_id, $member->name, $result['cached'], $result['ledger'], $result['ledger'] - $result['cached']];
                    }
                }
            });

        $this->info("Members checked: {$checked}. Ledger rows: " . MemberPointTransaction::query()->count() . '.');
        if ($mismatches === []) {
            $this->info('All balances match the ledger.');

            return self::SUCCESS;
        }

        $this->table(['member_id', 'organization_id', 'name', 'cached', 'ledger', 'diff'], $mismatches);
        $this->warn(count($mismatches) . ' mismatch(es) ' . ($fix ? 'fixed (cache = ledger).' : 'found. Re-run with --fix to set cache = ledger.'));

        return $fix ? self::SUCCESS : self::FAILURE;
    }

    private function expire(LoyaltyService $loyalty): int
    {
        $count = $loyalty->expireDue();
        $this->info("Expired lots: {$count}.");

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Action must be reconcile or expire.');

        return self::INVALID;
    }
}
