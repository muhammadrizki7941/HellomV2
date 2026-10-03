<?php

namespace App\Console\Commands;

use App\Models\CheckoutIntent;
use App\Models\FinanceJournalEntry;
use App\Models\LandingRefund;
use App\Models\OrganizationWalletTransaction;
use App\Models\ProductPurchase;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWithdrawal;
use App\Services\Finance\JournalRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Journals every money record that has no journal entry yet (history before the journal,
 * or a live hook that failed / was skipped by a query-builder update). Report only by
 * default: the run happens inside a transaction that is rolled back, so the numbers are
 * exactly what --force would write. Same event keys as the live hooks, so it is safe to
 * repeat (scheduled with --force --days=3).
 */
class FinanceJournalBackfillCommand extends Command
{
    protected $signature = 'finance:journal-backfill {--force : write the entries} {--days= : only records touched in the last N days}';

    protected $description = 'Report (or write with --force) finance journal entries missing for existing money records';

    public function handle(JournalRecorder $recorder): int
    {
        $since = $this->option('days') !== null ? now()->subDays(max(1, (int) $this->option('days'))) : null;
        $force = (bool) $this->option('force');

        $sources = [
            'Saldo penjual (ledger)' => [SellerLedgerEntry::query(), 'created_at', fn ($row) => $recorder->sellerLedger($row)],
            'Penarikan dibayar' => [SellerWithdrawal::query()->where('status', SellerWithdrawal::STATUS_PAID), 'updated_at', fn ($row) => $recorder->withdrawalPaid($row)],
            'Refund dibayar' => [LandingRefund::query()->where('status', LandingRefund::STATUS_PAID), 'updated_at', fn ($row) => $recorder->refundPaid($row)],
            'Produk Hellom' => [ProductPurchase::query()->whereIn('payment_status', ['paid', 'refunded'])->where('amount_paid', '>', 0), 'updated_at', fn ($row) => $recorder->productPurchase($row)],
            'Langganan (gateway/manual)' => [CheckoutIntent::query()->where('status', 'confirmed')->where('amount', '>', 0), 'updated_at', fn ($row) => $recorder->checkoutIntent($row)],
            'Dompet (top-up & langganan)' => [OrganizationWalletTransaction::query()->whereIn('type', array_merge(
                JournalRecorder::WALLET_TOPUP_CREDITS, JournalRecorder::WALLET_SUBSCRIPTION_DEBITS, ['transfer_to_seller_balance'],
            )), 'created_at', fn ($row) => $recorder->walletTransaction($row)],
        ];

        $before = FinanceJournalEntry::query()->max('id') ?? 0;
        $table = [];
        $failed = 0;

        DB::beginTransaction();
        try {
            foreach ($sources as $label => [$query, $column, $record]) {
                /** @var Builder $query */
                $scanned = 0;
                $countBefore = FinanceJournalEntry::query()->count();
                $query->when($since, fn ($q) => $q->where($column, '>=', $since))
                    ->chunkById(500, function ($rows) use ($record, &$scanned, &$failed): void {
                        foreach ($rows as $row) {
                            $scanned++;
                            try {
                                DB::transaction(fn () => $record($row));
                            } catch (\Throwable $e) {
                                $failed++;
                                $this->warn(class_basename($row) . " #{$row->id}: " . $e->getMessage());
                            }
                        }
                    });
                $table[] = [$label, $scanned, FinanceJournalEntry::query()->count() - $countBefore];
            }

            $written = FinanceJournalEntry::query()->where('id', '>', $before);
            $bySource = (clone $written)->selectRaw('source, provider, COUNT(*) AS entries, SUM(amount) AS amount')
                ->groupBy('source', 'provider')->orderBy('source')->get()
                ->map(fn ($r) => [$r->source, $r->provider ?? '-', (int) $r->entries, number_format((int) $r->amount, 0, ',', '.')])->all();

            if ($force) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->table(['Sumber', 'Dicek', $force ? 'Ditulis' : 'Akan ditulis'], $table);
        if ($bySource !== []) {
            $this->table(['source', 'provider', 'entri', 'nominal (Rp)'], $bySource);
        }
        if ($failed > 0) {
            $this->error("{$failed} baris gagal dijurnal (lihat peringatan di atas).");
        }
        $this->line($force ? 'Jurnal sudah ditulis.' : 'Mode laporan: belum ada yang ditulis. Jalankan lagi dengan --force untuk menulis.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
