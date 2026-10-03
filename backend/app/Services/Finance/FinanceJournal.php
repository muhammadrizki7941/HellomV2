<?php

namespace App\Services\Finance;

use App\Models\FinanceJournalEntry;
use App\Models\FinanceJournalLine;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Writer of the double-entry finance journal. One entry per business event (unique
 * event_key = idempotency), lines are signed rupiah (debit +, credit −) and must sum
 * to zero. Rows are never updated: a correction is a new entry.
 *
 * Accounts (string codes):
 *   asset      gateway:{ipaymu|xendit|doku}  money held at the gateway
 *              bank:hellom                   Hellom's bank (manual transfers, payouts)
 *   liability  seller:{org}:pending|available|processing  seller's sales balance
 *              wallet:{org}                  top-up wallet (not withdrawable)
 *              refund:payable                refunds owed to buyers
 *   revenue    revenue:platform_fee|digital_product|subscription|withdrawal_fee
 *   expense    expense:gateway_fee, hellom:adjustment
 *   equity     equity:opening
 */
final class FinanceJournal
{
    public const GATEWAYS = ['ipaymu', 'xendit', 'doku'];

    /** Account where money paid through $provider lands (manual/unknown = Hellom's bank). */
    public static function cashAccount(?string $provider): string
    {
        $provider = strtolower((string) $provider);

        return in_array($provider, self::GATEWAYS, true) ? 'gateway:' . $provider : 'bank:hellom';
    }

    public static function sellerAccount(int $organizationId, string $bucket): string
    {
        return "seller:{$organizationId}:{$bucket}";
    }

    public static function accountType(string $account): string
    {
        return match (true) {
            str_starts_with($account, 'gateway:'), str_starts_with($account, 'bank:') => 'asset',
            str_starts_with($account, 'seller:'), str_starts_with($account, 'wallet:'), str_starts_with($account, 'refund:') => 'liability',
            str_starts_with($account, 'revenue:') => 'revenue',
            str_starts_with($account, 'equity:') => 'equity',
            default => 'expense',
        };
    }

    /** Organization owning an account (seller:{org}:…, wallet:{org}), else null. */
    public static function accountOrganization(string $account): ?int
    {
        return preg_match('/^(?:seller|wallet):(\d+)/', $account, $m) ? (int) $m[1] : null;
    }

    public static function exists(string $eventKey): bool
    {
        return FinanceJournalEntry::query()->where('event_key', $eventKey)->exists();
    }

    /**
     * Append one balanced entry. Returns null when the event was journaled already.
     *
     * @param array{event_type:string, source:string, source_type?:?string, source_id?:?int, provider?:?string,
     *              organization_id?:?int, amount?:int, occurred_at?:?CarbonInterface, description?:?string, metadata?:?array} $entry
     * @param array<string,int> $lines account => signed amount (debit +, credit −); zero lines are dropped
     */
    public function post(string $eventKey, array $entry, array $lines): ?FinanceJournalEntry
    {
        $lines = array_filter($lines, fn (int $amount): bool => $amount !== 0);
        if ($lines === []) {
            return null;
        }
        if (array_sum($lines) !== 0) {
            throw new InvalidArgumentException("Journal entry {$eventKey} is not balanced: " . json_encode($lines));
        }
        if (self::exists($eventKey)) {
            return null;
        }

        $occurredAt = $entry['occurred_at'] ?? now();

        try {
            // Savepoint inside the caller's transaction: a duplicate key only undoes this entry.
            return DB::transaction(function () use ($eventKey, $entry, $lines, $occurredAt): FinanceJournalEntry {
                $row = FinanceJournalEntry::query()->create([
                    'event_key' => $eventKey,
                    'event_type' => $entry['event_type'],
                    'source' => $entry['source'],
                    'source_type' => $entry['source_type'] ?? null,
                    'source_id' => $entry['source_id'] ?? null,
                    'provider' => $entry['provider'] ?? null,
                    'organization_id' => $entry['organization_id'] ?? null,
                    'amount' => (int) ($entry['amount'] ?? max($lines)),
                    'occurred_at' => $occurredAt,
                    'description' => isset($entry['description']) ? mb_substr((string) $entry['description'], 0, 255) : null,
                    'metadata' => $entry['metadata'] ?? null,
                ]);
                foreach ($lines as $account => $amount) {
                    FinanceJournalLine::query()->create([
                        'entry_id' => $row->id,
                        'account' => $account,
                        'account_type' => self::accountType($account),
                        'organization_id' => self::accountOrganization($account) ?? ($entry['organization_id'] ?? null),
                        'amount' => $amount,
                        'occurred_at' => $occurredAt,
                    ]);
                }

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // the same event was journaled concurrently
        }
    }

    /**
     * Balance per account from the journal (signed: assets/expenses positive,
     * liabilities/revenue/equity negative).
     *
     * @return array<string,int>
     */
    public function balances(?string $accountPrefix = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        return FinanceJournalLine::query()
            ->when($accountPrefix, fn ($q) => $q->where('account', 'like', $accountPrefix . '%'))
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('occurred_at', '<', $to))
            ->selectRaw('account, SUM(amount) AS total')
            ->groupBy('account')
            ->pluck('total', 'account')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }
}
