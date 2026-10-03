<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\FinanceJournalEntry;
use App\Models\FinanceJournalLine;
use App\Models\Organization;
use App\Services\Finance\FinanceJournal;
use App\Services\Payments\GatewayRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Super admin › Keuangan: every money movement from the double-entry journal, across
 * gateways (iPaymu / Xendit / DOKU / manual), Hellom's own products, subscriptions,
 * wallet top-ups and seller (Hellom Page) sales. Read only.
 */
class AdminFinanceJournalController extends BaseApiController
{
    /** Events that bring new money in (wallet-paid subscriptions were counted at top-up). */
    private const MONEY_IN = ['sale', 'product_paid', 'subscription_paid', 'wallet_topup'];

    private const PROVIDERS = ['ipaymu', 'xendit', 'doku', 'manual'];

    private const RANGES = [7, 30, 90, 365];

    public function summary(Request $request, GatewayRegistry $gateways): JsonResponse
    {
        $days = in_array((int) $request->query('days'), self::RANGES, true) ? (int) $request->query('days') : 30;
        $from = CarbonImmutable::now()->subDays($days - 1)->startOfDay();

        $inPeriod = fn (): Builder => FinanceJournalEntry::query()->where('occurred_at', '>=', $from);
        $moneyIn = fn (): Builder => $inPeriod()->whereIn('event_type', self::MONEY_IN)->where(fn ($q) => $q->whereNull('provider')->orWhere('provider', '!=', 'wallet'));

        $periodLines = FinanceJournalLine::query()->where('occurred_at', '>=', $from)
            ->selectRaw('account, SUM(amount) AS total')->groupBy('account')->pluck('total', 'account')->map(fn ($v): int => (int) $v);
        $revenueByAccount = $periodLines->filter(fn ($v, $account) => str_starts_with((string) $account, 'revenue:'))->map(fn ($v): int => -$v);
        $gatewayFees = (int) ($periodLines['expense:gateway_fee'] ?? 0);
        $adjustments = (int) ($periodLines['hellom:adjustment'] ?? 0);
        $revenue = (int) $revenueByAccount->sum();

        // Liabilities: money Hellom holds for others (all time).
        $liabilities = FinanceJournalLine::query()->where('account_type', 'liability')
            ->selectRaw("CASE WHEN account LIKE 'seller:%:pending' THEN 'seller_pending' WHEN account LIKE 'seller:%:available' THEN 'seller_available'
                WHEN account LIKE 'seller:%:processing' THEN 'seller_processing' WHEN account LIKE 'wallet:%' THEN 'wallets' ELSE 'refunds' END AS bucket, SUM(amount) AS total")
            ->groupBy('bucket')->pluck('total', 'bucket')->map(fn ($v): int => -(int) $v);

        $byProvider = $moneyIn()->selectRaw('provider, COUNT(*) AS cnt, SUM(amount) AS gross')->groupBy('provider')->get()->keyBy('provider');
        $feesByProvider = FinanceJournalLine::query()->where('finance_journal_lines.account', 'expense:gateway_fee')->where('finance_journal_lines.occurred_at', '>=', $from)
            ->join('finance_journal_entries as e', 'e.id', '=', 'finance_journal_lines.entry_id')
            ->selectRaw('e.provider, SUM(finance_journal_lines.amount) AS total')->groupBy('e.provider')->pluck('total', 'provider');
        $cashBalances = app(FinanceJournal::class)->balances('gateway:') + app(FinanceJournal::class)->balances('bank:');

        $providers = [];
        foreach (self::PROVIDERS as $provider) {
            $row = $byProvider->get($provider);
            $providers[] = [
                'provider' => $provider,
                'label' => ['ipaymu' => 'iPaymu', 'xendit' => 'Xendit', 'doku' => 'DOKU', 'manual' => 'Transfer manual'][$provider],
                'transactions' => (int) ($row->cnt ?? 0),
                'gross' => (int) ($row->gross ?? 0),
                'gateway_fees' => (int) ($feesByProvider[$provider] ?? 0),
                'journal_balance' => (int) ($cashBalances[FinanceJournal::cashAccount($provider)] ?? 0),
                'live_balance' => $provider === 'manual' ? null : $this->liveBalance($gateways, $provider, $request->boolean('refresh')),
            ];
        }

        $bySource = $moneyIn()->selectRaw('source, COUNT(*) AS cnt, SUM(amount) AS gross')->groupBy('source')->get()
            ->map(fn ($r) => ['source' => $r->source, 'transactions' => (int) $r->cnt, 'gross' => (int) $r->gross])->values();

        // Daily trend: new money in, Hellom revenue, gateway fees.
        $grossDaily = $moneyIn()->selectRaw('DATE(occurred_at) AS day, SUM(amount) AS total')->groupBy('day')->pluck('total', 'day');
        $lineDaily = FinanceJournalLine::query()->where('occurred_at', '>=', $from)
            ->where(fn ($q) => $q->where('account', 'like', 'revenue:%')->orWhere('account', 'expense:gateway_fee'))
            ->selectRaw("DATE(occurred_at) AS day, SUM(CASE WHEN account LIKE 'revenue:%' THEN -amount ELSE 0 END) AS revenue, SUM(CASE WHEN account = 'expense:gateway_fee' THEN amount ELSE 0 END) AS fees")
            ->groupBy('day')->get()->keyBy('day');
        $trend = [];
        for ($day = $from; $day->lessThanOrEqualTo(CarbonImmutable::now()); $day = $day->addDay()) {
            $key = $day->toDateString();
            $trend[] = [
                'date' => $key,
                'gross' => (int) ($grossDaily[$key] ?? 0),
                'revenue' => (int) ($lineDaily[$key]->revenue ?? 0),
                'gateway_fees' => (int) ($lineDaily[$key]->fees ?? 0),
            ];
        }

        $sales = $inPeriod()->where('event_type', 'sale')->whereNotNull('organization_id')
            ->selectRaw('organization_id, COUNT(*) AS cnt, SUM(amount) AS gross')->groupBy('organization_id')->orderByDesc('gross')->limit(8)->get();
        $platformFees = $inPeriod()->where('event_type', 'platform_fee')->whereIn('organization_id', $sales->pluck('organization_id'))
            ->selectRaw('organization_id, SUM(amount) AS total')->groupBy('organization_id')->pluck('total', 'organization_id');
        $names = Organization::query()->whereIn('id', $sales->pluck('organization_id'))->pluck('name', 'id');
        $topSellers = $sales->map(fn ($r) => [
            'organization_id' => (int) $r->organization_id,
            'name' => (string) ($names[$r->organization_id] ?? ('#' . $r->organization_id)),
            'orders' => (int) $r->cnt,
            'gross' => (int) $r->gross,
            'platform_fee' => (int) ($platformFees[$r->organization_id] ?? 0),
        ])->values();

        return $this->ok([
            'range' => ['days' => $days, 'from' => $from->toDateString(), 'to' => CarbonImmutable::now()->toDateString()],
            'totals' => [
                'gross' => (int) $moneyIn()->sum('amount'),
                'transactions' => (int) $moneyIn()->count(),
                'revenue' => $revenue,
                'revenue_by_account' => $revenueByAccount->all(),
                'gateway_fees' => $gatewayFees,
                'adjustments' => $adjustments,
                'hellom_net' => $revenue - $gatewayFees - $adjustments,
            ],
            'liabilities' => [
                'seller_pending' => (int) ($liabilities['seller_pending'] ?? 0),
                'seller_available' => (int) ($liabilities['seller_available'] ?? 0),
                'seller_processing' => (int) ($liabilities['seller_processing'] ?? 0),
                'wallets' => (int) ($liabilities['wallets'] ?? 0),
                'refunds' => (int) ($liabilities['refunds'] ?? 0),
            ],
            'providers' => $providers,
            'sources' => $bySource,
            'trend' => $trend,
            'top_sellers' => $topSellers,
            'journal' => [
                'entries' => FinanceJournalEntry::query()->count(),
                'last_entry_at' => FinanceJournalEntry::query()->max('occurred_at'),
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'max:20'],
            'source' => ['nullable', 'string', 'max:30'],
            'event_type' => ['nullable', 'string', 'max:40'],
            'organization_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $page = FinanceJournalEntry::query()
            ->with(['lines:id,entry_id,account,account_type,amount', 'organization:id,name'])
            ->when($validated['provider'] ?? null, fn ($q, $v) => $q->where('provider', $v))
            ->when($validated['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($validated['event_type'] ?? null, fn ($q, $v) => $q->where('event_type', $v))
            ->when($validated['organization_id'] ?? null, fn ($q, $v) => $q->where('organization_id', $v))
            ->when($validated['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', CarbonImmutable::parse($v)->startOfDay()))
            ->when($validated['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<', CarbonImmutable::parse($v)->addDay()->startOfDay()))
            ->when($validated['q'] ?? null, function ($q, $term) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
                $q->where(fn ($w) => $w->where('description', 'like', $like)->orWhere('event_key', 'like', $like)
                    ->orWhereIn('organization_id', Organization::query()->where('name', 'like', $like)->select('id')));
            })
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->ok([
            'items' => collect($page->items())->map(fn (FinanceJournalEntry $entry) => [
                'id' => (int) $entry->id,
                'event_key' => $entry->event_key,
                'event_type' => $entry->event_type,
                'source' => $entry->source,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id ? (int) $entry->source_id : null,
                'provider' => $entry->provider,
                'organization' => $entry->organization ? ['id' => (int) $entry->organization->id, 'name' => $entry->organization->name] : null,
                'amount' => (int) $entry->amount,
                'description' => $entry->description,
                'occurred_at' => $entry->occurred_at?->toISOString(),
                'lines' => $entry->lines->map(fn (FinanceJournalLine $line) => [
                    'account' => $line->account, 'account_type' => $line->account_type, 'amount' => (int) $line->amount,
                ])->values(),
            ])->values(),
            'pagination' => [
                'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** Gateway balance from the provider API, cached a minute (null when unavailable). */
    private function liveBalance(GatewayRegistry $gateways, string $provider, bool $refresh): ?array
    {
        $key = "finance:gateway-balance:{$provider}";
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 60, function () use ($gateways, $provider): ?array {
            try {
                $gateway = $gateways->get($provider);
                if (!$gateway->isReady()) {
                    return null;
                }
                $balance = $gateway->getBalance();

                return $balance ? ['available' => $balance->available, 'pending' => $balance->pending, 'checked_at' => now()->toISOString()] : null;
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
        });
    }
}
