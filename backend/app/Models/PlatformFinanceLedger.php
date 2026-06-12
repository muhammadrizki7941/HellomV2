<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    // Helper methods for different transaction types
    public static function recordRevenue(string $category, int $amount, ?int $organizationId = null, ?string $referenceType = null, ?int $referenceId = null, ?string $description = null): self
    {
        $currentBalance = self::getCurrentBalance();
        $newBalance = $currentBalance + $amount;

        return self::create([
            'type' => 'revenue',
            'category' => $category,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'organization_id' => $organizationId,
            'currency' => 'IDR',
            'amount' => $amount,
            'balance_before' => $currentBalance,
            'balance_after' => $newBalance,
            'description' => $description,
            'effective_at' => now(),
        ]);
    }

    public static function recordExpense(string $category, int $amount, ?string $referenceType = null, ?int $referenceId = null, ?string $description = null): self
    {
        $currentBalance = self::getCurrentBalance();
        $newBalance = $currentBalance - $amount;

        return self::create([
            'type' => 'expense',
            'category' => $category,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'currency' => 'IDR',
            'amount' => -$amount, // negative for expense
            'balance_before' => $currentBalance,
            'balance_after' => $newBalance,
            'description' => $description,
            'effective_at' => now(),
        ]);
    }

    public static function getCurrentBalance(): int
    {
        return (int) self::latest('effective_at')->value('balance_after') ?? 0;
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
