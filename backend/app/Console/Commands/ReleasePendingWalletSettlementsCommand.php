<?php

namespace App\Console\Commands;

use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReleasePendingWalletSettlementsCommand extends Command
{
    protected $signature = 'hellom:wallet:release-pending-settlements
        {--organization-id= : Process only one organization id}
        {--limit=300 : Max pending entries to scan}
        {--dry-run : Simulate without DB writes}';

    protected $description = 'Release pending wallet payment settlements to available balance when settlement ETA has passed';

    public function handle(): int
    {
        $organizationId = (int) ($this->option('organization-id') ?? 0);
        $limit = (int) ($this->option('limit') ?? 300);
        $dryRun = (bool) $this->option('dry-run');

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 5000) {
            $limit = 5000;
        }

        $query = OrganizationWalletTransaction::query()
            ->where('type', 'payment_credit_pending')
            ->orderBy('id')
            ->limit($limit);

        if ($organizationId > 0) {
            $query->where('organization_id', $organizationId);
        }

        $rows = $query->get();

        $summary = [
            'scanned' => (int) $rows->count(),
            'released' => 0,
            'skipped_not_due' => 0,
            'skipped_already_released' => 0,
            'skipped_missing_wallet' => 0,
            'skipped_invalid_amount' => 0,
        ];

        $now = now();

        foreach ($rows as $pendingTx) {
            $result = $this->processPendingTransaction($pendingTx, $now, $dryRun);
            if (array_key_exists($result, $summary)) {
                $summary[$result]++;
            }
        }

        $mode = $dryRun ? 'DRY RUN' : 'EXECUTED';
        $this->info("Pending settlement release {$mode} summary:");
        foreach ($summary as $key => $value) {
            $this->line("- {$key}: {$value}");
        }

        return 0;
    }

    private function processPendingTransaction(OrganizationWalletTransaction $pendingTx, $now, bool $dryRun): string
    {
        $metadata = is_array($pendingTx->metadata) ? $pendingTx->metadata : [];
        $settlementEtaRaw = $metadata['settlement_eta'] ?? null;

        if (!$settlementEtaRaw) {
            return 'skipped_not_due';
        }

        $settlementEta = now()->parse((string) $settlementEtaRaw);
        if ($settlementEta->gt($now)) {
            return 'skipped_not_due';
        }

        if ($dryRun) {
            $alreadyReleased = OrganizationWalletTransaction::query()
                ->where('type', 'payment_settle_release')
                ->where('organization_id', (int) $pendingTx->organization_id)
                ->where('reference_type', 'organization_wallet_transactions')
                ->where('reference_id', (string) $pendingTx->id)
                ->exists();

            if ($alreadyReleased) {
                return 'skipped_already_released';
            }

            $amount = (int) $pendingTx->amount;
            if ($amount <= 0) {
                return 'skipped_invalid_amount';
            }

            return 'released';
        }

        return DB::transaction(function () use ($pendingTx, $now): string {
            $lockedPendingTx = OrganizationWalletTransaction::query()
                ->where('id', (int) $pendingTx->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedPendingTx instanceof OrganizationWalletTransaction) {
                return 'skipped_not_due';
            }

            $metadata = is_array($lockedPendingTx->metadata) ? $lockedPendingTx->metadata : [];
            $settlementEtaRaw = $metadata['settlement_eta'] ?? null;
            if (!$settlementEtaRaw) {
                return 'skipped_not_due';
            }

            $settlementEta = now()->parse((string) $settlementEtaRaw);
            if ($settlementEta->gt($now)) {
                return 'skipped_not_due';
            }

            $alreadyReleased = OrganizationWalletTransaction::query()
                ->where('type', 'payment_settle_release')
                ->where('organization_id', (int) $lockedPendingTx->organization_id)
                ->where('reference_type', 'organization_wallet_transactions')
                ->where('reference_id', (string) $lockedPendingTx->id)
                ->exists();

            if ($alreadyReleased) {
                return 'skipped_already_released';
            }

            $wallet = OrganizationWallet::query()
                ->where('id', (int) $lockedPendingTx->wallet_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet instanceof OrganizationWallet) {
                return 'skipped_missing_wallet';
            }

            $amount = (int) $lockedPendingTx->amount;
            if ($amount <= 0) {
                return 'skipped_invalid_amount';
            }

            $wallet->forceFill([
                'pending_balance' => max(0, (int) $wallet->pending_balance - $amount),
                'available_balance' => (int) $wallet->available_balance + $amount,
            ])->save();

            OrganizationWalletTransaction::query()->create([
                'organization_id' => (int) $wallet->organization_id,
                'wallet_id' => (int) $wallet->id,
                'user_id' => null,
                'type' => 'payment_settle_release',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => (int) $wallet->available_balance,
                'reference_type' => 'organization_wallet_transactions',
                'reference_id' => (string) $lockedPendingTx->id,
                'external_ref' => (string) ($lockedPendingTx->external_ref ?? ''),
                'description' => 'Pending settlement auto-released by ETA fallback',
                'metadata' => [
                    'source' => 'scheduler_eta_fallback',
                    'settlement_eta' => $settlementEta->toISOString(),
                    'released_at' => $now->toISOString(),
                ],
            ]);

            $lockedMeta = is_array($lockedPendingTx->metadata) ? $lockedPendingTx->metadata : [];
            $lockedMeta['settlement_status'] = 'settled';
            $lockedMeta['settlement_released_at'] = $now->toISOString();
            $lockedMeta['settlement_release_source'] = 'scheduler_eta_fallback';

            $lockedPendingTx->forceFill([
                'metadata' => $lockedMeta,
            ])->save();

            return 'released';
        });
    }
}
