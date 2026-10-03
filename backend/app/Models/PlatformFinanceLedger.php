<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class PlatformFinanceLedger extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'category',
        'reference_type',
        'reference_id',
        'organization_id',
        'currency',
        'amount',
        'balance_before',
        'balance_after',
        'description',
        'metadata',
        'effective_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'effective_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function recordRevenue(string $category, int $amount, ?int $organizationId = null, ?string $referenceType = null, ?int $referenceId = null, ?string $description = null): self
    {
        return self::appendRow('revenue', $category, $amount, $organizationId, $referenceType, $referenceId, $description);
    }

    public static function recordExpense(string $category, int $amount, ?string $referenceType = null, ?int $referenceId = null, ?string $description = null): self
    {
        return self::appendRow('expense', $category, -$amount, null, $referenceType, $referenceId, $description); // negative for expense
    }

    /**
     * Append one row. The latest row is locked so concurrent writers cannot read the same
     * running balance, and a row for the same (type, category, reference) is returned
     * instead of being written twice (webhook + return URL + reconcile).
     */
    private static function appendRow(string $type, string $category, int $amount, ?int $organizationId, ?string $referenceType, ?int $referenceId, ?string $description): self
    {
        return DB::transaction(function () use ($type, $category, $amount, $organizationId, $referenceType, $referenceId, $description): self {
            $latest = self::query()->orderByDesc('effective_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($referenceType !== null && $referenceId !== null) {
                $existing = self::query()->where('type', $type)->where('category', $category)
                    ->where('reference_type', $referenceType)->where('reference_id', $referenceId)->first();
                if ($existing) {
                    return $existing;
                }
            }
            $currentBalance = (int) ($latest?->balance_after ?? 0);

            return self::create([
                'type' => $type,
                'category' => $category,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'organization_id' => $organizationId,
                'currency' => 'IDR',
                'amount' => $amount,
                'balance_before' => $currentBalance,
                'balance_after' => $currentBalance + $amount,
                'description' => $description,
                'effective_at' => now(),
            ]);
        }, 3);
    }

    public static function getCurrentBalance(): int
    {
        return (int) (self::query()->orderByDesc('effective_at')->orderByDesc('id')->value('balance_after') ?? 0);
    }

    public static function getRevenueSummary(int $days = 30): array
    {
        $startAt = now()->subDays($days);

        $revenues = self::query()
            ->where('type', 'revenue')
            ->where('effective_at', '>=', $startAt)
            ->get();

        $fallbackSummary = self::getUnreconciledCheckoutRevenueSummary($startAt);
        $byCategory = $revenues->groupBy('category')->map->sum('amount')->all();

        if ($fallbackSummary['total_revenue'] > 0) {
            $byCategory['checkout_confirmed_unreconciled'] = ($byCategory['checkout_confirmed_unreconciled'] ?? 0) + $fallbackSummary['total_revenue'];
        }

        return [
            'total_revenue' => (int) $revenues->sum('amount') + $fallbackSummary['total_revenue'],
            'revenue_count' => (int) $revenues->count() + $fallbackSummary['revenue_count'],
            'by_category' => $byCategory,
        ];
    }

    public static function getWithdrawableRevenue(): int
    {
        // Revenue that can be withdrawn = total revenue - pending payouts - platform reserves
        $totalRevenue = self::getCurrentBalance() + self::getUnreconciledCheckoutRevenueSummary()['total_revenue'];
        $pendingPayouts = PlatformPayout::query()
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        return max(0, $totalRevenue - $pendingPayouts);
    }

    /**
     * Confirmed checkout intents created before ledger tracking should still count
     * in platform finance until they are fully backfilled into the ledger table.
     *
     * @return array{total_revenue:int,revenue_count:int}
     */
    public static function getUnreconciledCheckoutRevenueSummary($startAt = null): array
    {
        $query = self::unreconciledCheckoutQuery();

        if ($startAt) {
            $query->where('created_at', '>=', $startAt);
        }

        return [
            'total_revenue' => (int) $query->sum('amount'),
            'revenue_count' => (int) $query->count(),
        ];
    }

    private static function unreconciledCheckoutQuery(): Builder
    {
        return CheckoutIntent::query()
            ->where('status', 'confirmed')
            ->where('amount', '>', 0)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('platform_finance_ledgers')
                    ->where('reference_type', 'checkout_intents')
                    ->whereColumn('platform_finance_ledgers.reference_id', 'checkout_intents.id');
            });
    }
}
