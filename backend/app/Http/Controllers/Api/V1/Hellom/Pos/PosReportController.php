<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\Hpp\HppCostSnapshot;
use App\Models\Hpp\HppProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\PosStaffCashLog;
use App\Services\Pos\PosReportExcelExporter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PosReportController extends BasePosController
{
    /**
     * Resolve which outlet(s) a report covers.
     * - scope=all (owner only) → every outlet of the organization (aggregate).
     * - otherwise → just the active outlet.
     */
    private function reportScope(Request $request, Organization $org): array
    {
        $wantsAll = in_array((string) $request->input('scope'), ['all', 'aggregate'], true);

        if ($wantsAll && $this->isOrgOwner($request, $org)) {
            $outlets = Outlet::query()
                ->where('organization_id', $org->id)
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $slugs = $outlets->pluck('tenant_slug')->filter()->values()->all();
            if ($slugs === []) {
                $slugs = [$this->getActiveTenantSlug($request, $org)];
            }

            return ['slugs' => $slugs, 'aggregate' => true, 'outlets' => $outlets, 'is_owner' => true];
        }

        return [
            'slugs' => [$this->getActiveTenantSlug($request, $org)],
            'aggregate' => false,
            'outlets' => collect(),
            'is_owner' => $this->isOrgOwner($request, $org),
        ];
    }

    /**
     * Report dates are calendar dates in the selected business timezone; timestamps are stored
     * in UTC. Paid date is the default basis because sales reports should follow settlement time.
     */
    private function reportContext(Request $request): array
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'timezone'],
            'date_basis' => ['nullable', 'in:paid_at,created_at'],
        ]);
        $timezone = $validated['timezone'] ?? config('app.timezone');
        $today = Carbon::now($timezone)->toDateString();
        $startLocal = Carbon::createFromFormat('Y-m-d', $validated['start_date'] ?? $today, $timezone)->startOfDay();
        $endLocal = Carbon::createFromFormat('Y-m-d', $validated['end_date'] ?? $today, $timezone)->endOfDay();

        if ($endLocal->lt($startLocal)) {
            throw ValidationException::withMessages(['end_date' => 'Tanggal akhir harus sama atau setelah tanggal mulai.']);
        }

        return [
            'timezone' => $timezone,
            'date_basis' => $validated['date_basis'] ?? 'paid_at',
            'start_local' => $startLocal,
            'end_local' => $endLocal,
            'start_utc' => $startLocal->copy()->utc(),
            'end_utc' => $endLocal->copy()->utc(),
        ];
    }

    private function settledOrders(array $slugs, Carbon $start, Carbon $end, string $dateBasis = 'paid_at'): Builder
    {
        $dateColumn = $dateBasis === 'created_at' ? 'created_at' : 'paid_at';
        $query = Order::withoutGlobalScope('tenant')
            ->whereIn('tenant_id', $slugs)
            ->whereIn('payment_status', ['paid', 'refunded']);

        if ($dateColumn === 'paid_at') {
            $query->whereBetween(DB::raw('COALESCE(paid_at, created_at)'), [$start, $end]);
        } else {
            $query->whereBetween('created_at', [$start, $end]);
        }

        return $query;
    }

    private function collectedAmountExpression(): string
    {
        return 'COALESCE(NULLIF(final_amount, 0), total_amount)';
    }

    private function discountExpression(): string
    {
        return '(COALESCE(discount_amount, 0) + COALESCE(points_discount_amount, 0) + COALESCE(manual_discount_amount, 0))';
    }

    public function summary(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $context = $this->reportContext($request);
        $scope = $this->reportScope($request, $org);
        $slugs = $scope['slugs'];
        $dateBasis = $context['date_basis'];

        $current = $this->settledOrders($slugs, $context['start_utc'], $context['end_utc'], $dateBasis)
            ->selectRaw('
                COUNT(*) as settled_orders,
                SUM(CASE WHEN payment_status = "paid" THEN 1 ELSE 0 END) as paid_orders,
                SUM(CASE WHEN payment_status = "refunded" THEN 1 ELSE 0 END) as refunded_orders,
                COALESCE(SUM(COALESCE(NULLIF(subtotal_amount, 0), total_amount)), 0) as gross_sales,
                COALESCE(SUM(' . $this->discountExpression() . '), 0) as discounts,
                COALESCE(SUM(service_amount), 0) as service_total,
                COALESCE(SUM(tax_amount), 0) as tax_total,
                COALESCE(SUM(rounding_amount), 0) as rounding_total,
                COALESCE(SUM(refund_amount), 0) as refunds,
                COALESCE(SUM(' . $this->collectedAmountExpression() . ' - COALESCE(refund_amount, 0)), 0) as net_collected
            ')
            ->first();

        $diffDays = $context['start_local']->diffInDays($context['end_local']) + 1;
        $previousStartLocal = $context['start_local']->copy()->subDays($diffDays)->startOfDay();
        $previousEndLocal = $context['start_local']->copy()->subDay()->endOfDay();
        $previous = $this->settledOrders(
            $slugs,
            $previousStartLocal->copy()->utc(),
            $previousEndLocal->copy()->utc(),
            $dateBasis
        )->selectRaw('
            SUM(CASE WHEN payment_status = "paid" THEN 1 ELSE 0 END) as paid_orders,
            COALESCE(SUM(' . $this->collectedAmountExpression() . ' - COALESCE(refund_amount, 0)), 0) as net_collected
        ')->first();

        $revenue = (int) ($current->net_collected ?? 0);
        $ordersCount = (int) ($current->paid_orders ?? 0);
        $previousRevenue = (int) ($previous->net_collected ?? 0);
        $previousOrders = (int) ($previous->paid_orders ?? 0);
        $revenueChange = $previousRevenue > 0 ? round((($revenue - $previousRevenue) / $previousRevenue) * 100, 1) : null;
        $ordersChange = $previousOrders > 0 ? round((($ordersCount - $previousOrders) / $previousOrders) * 100, 1) : null;

        $paymentBreakdown = $this->settledOrders($slugs, $context['start_utc'], $context['end_utc'], $dateBasis)
            ->selectRaw('payment_method, COUNT(*) as count, COALESCE(SUM(' . $this->collectedAmountExpression() . ' - COALESCE(refund_amount, 0)), 0) as total')
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get();

        $serviceBreakdown = $this->settledOrders($slugs, $context['start_utc'], $context['end_utc'], $dateBasis)
            ->selectRaw('service_type, COUNT(*) as count, COALESCE(SUM(' . $this->collectedAmountExpression() . ' - COALESCE(refund_amount, 0)), 0) as total')
            ->groupBy('service_type')
            ->orderByDesc('total')
            ->get();

        $createdStart = $context['start_utc'];
        $createdEnd = $context['end_utc'];
        $unpaidOrders = Order::withoutGlobalScope('tenant')
            ->whereIn('tenant_id', $slugs)
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$createdStart, $createdEnd])
            ->count();
        $cancelledOrders = Order::withoutGlobalScope('tenant')
            ->whereIn('tenant_id', $slugs)
            ->where('status', 'cancelled')
            ->whereBetween('cancelled_at', [$createdStart, $createdEnd])
            ->count();

        $itemsSold = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.tenant_id', $slugs)
            ->where('orders.payment_status', 'paid');
        $this->applyDateRange($itemsSold, $dateBasis, $context['start_utc'], $context['end_utc'], 'orders');
        $totalItems = (int) $itemsSold->sum('order_items.qty');

        $outletBreakdown = [];
        if ($scope['aggregate']) {
            $rows = $this->settledOrders($slugs, $context['start_utc'], $context['end_utc'], $dateBasis)
                ->selectRaw('tenant_id, SUM(CASE WHEN payment_status = "paid" THEN 1 ELSE 0 END) as orders_count, COALESCE(SUM(' . $this->collectedAmountExpression() . ' - COALESCE(refund_amount, 0)), 0) as revenue')
                ->groupBy('tenant_id')
                ->get()
                ->keyBy('tenant_id');

            foreach ($scope['outlets'] as $outlet) {
                $row = $rows->get($outlet->tenant_slug);
                $outletBreakdown[] = [
                    'outlet_id' => $outlet->id,
                    'name' => $outlet->name,
                    'is_primary' => (bool) $outlet->is_primary,
                    'total_orders' => (int) ($row->orders_count ?? 0),
                    'total_revenue' => (int) ($row->revenue ?? 0),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'scope' => $scope['aggregate'] ? 'all_outlets' : 'single_outlet',
                'is_owner' => (bool) $scope['is_owner'],
                'period' => [
                    'start' => $context['start_local']->toDateString(),
                    'end' => $context['end_local']->toDateString(),
                    'days' => $diffDays,
                    'timezone' => $context['timezone'],
                    'date_basis' => $dateBasis,
                ],
                'summary' => [
                    'total_revenue' => $revenue,
                    'net_collected' => $revenue,
                    'gross_sales' => (int) ($current->gross_sales ?? 0),
                    'discount_total' => (int) ($current->discounts ?? 0),
                    'net_sales' => max(0, (int) ($current->gross_sales ?? 0) - (int) ($current->discounts ?? 0)),
                    'service_total' => (int) ($current->service_total ?? 0),
                    'tax_total' => (int) ($current->tax_total ?? 0),
                    'rounding_total' => (int) ($current->rounding_total ?? 0),
                    'refunded_total' => (int) ($current->refunds ?? 0),
                    'total_orders' => $ordersCount,
                    'settled_orders' => (int) ($current->settled_orders ?? 0),
                    'refunded_orders' => (int) ($current->refunded_orders ?? 0),
                    'unpaid_orders' => $unpaidOrders,
                    'cancelled_orders' => $cancelledOrders,
                    'avg_order_value' => $ordersCount > 0 ? (int) round($revenue / $ordersCount) : 0,
                    'total_items_sold' => $totalItems,
                    'revenue_change' => $revenueChange,
                    'orders_change' => $ordersChange,
                ],
                'payment_breakdown' => $paymentBreakdown,
                'service_breakdown' => $serviceBreakdown,
                'outlet_breakdown' => $outletBreakdown,
                'generated_at' => now()->toIso8601String(),
            ],
            'message' => 'Laporan berhasil dimuat',
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $context = $this->reportContext($request);
        $slugs = $this->reportScope($request, $org)['slugs'];
        $limit = min(50, max(1, (int) $request->input('limit', 10)));

        $topProductsQuery = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.tenant_id', $slugs)
            ->where('orders.payment_status', 'paid');
        $this->applyDateRange($topProductsQuery, $context['date_basis'], $context['start_utc'], $context['end_utc'], 'orders');
        $topProducts = $topProductsQuery
            ->selectRaw('
                order_items.product_id,
                order_items.product_name,
                SUM(order_items.qty) as total_qty,
                SUM(order_items.line_total) as total_revenue,
                AVG(order_items.unit_price) as avg_price,
                COUNT(DISTINCT orders.id) as order_count
            ')
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get();

        $topCategoriesQuery = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('orders.tenant_id', $slugs)
            ->where('orders.payment_status', 'paid');
        $this->applyDateRange($topCategoriesQuery, $context['date_basis'], $context['start_utc'], $context['end_utc'], 'orders');
        $topCategories = $topCategoriesQuery
            ->selectRaw('categories.name as category_name, SUM(order_items.qty) as total_qty, SUM(order_items.line_total) as total_revenue')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_revenue')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'top_products' => $topProducts,
                'top_categories' => $topCategories,
                'period' => ['start' => $context['start_local']->toDateString(), 'end' => $context['end_local']->toDateString()],
            ],
            'message' => 'Laporan produk berhasil dimuat',
        ]);
    }

    public function daily(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $context = $this->reportContext($request);
        $slugs = $this->reportScope($request, $org)['slugs'];
        $orders = $this->settledOrders($slugs, $context['start_utc'], $context['end_utc'], $context['date_basis'])
            ->select(['id', 'created_at', 'paid_at', 'payment_status', 'final_amount', 'total_amount', 'refund_amount'])
            ->cursor();

        $daily = [];
        $hours = [];
        foreach ($orders as $order) {
            $timestamp = $context['date_basis'] === 'created_at' ? $order->created_at : ($order->paid_at ?? $order->created_at);
            $localDate = $timestamp->copy()->setTimezone($context['timezone']);
            $day = $localDate->toDateString();
            $amount = (int) ($order->final_amount ?: $order->total_amount) - (int) $order->refund_amount;
            $daily[$day] ??= ['total_orders' => 0, 'total_revenue' => 0];
            if ($order->payment_status === 'paid') {
                $daily[$day]['total_orders']++;
            }
            $daily[$day]['total_revenue'] += $amount;
            $hour = $localDate->hour;
            $hours[$hour] ??= ['hour' => $hour, 'count' => 0, 'total' => 0];
            if ($order->payment_status === 'paid') {
                $hours[$hour]['count']++;
            }
            $hours[$hour]['total'] += $amount;
        }

        $filledData = [];
        $period = new \DatePeriod(
            $context['start_local']->copy()->startOfDay(),
            new \DateInterval('P1D'),
            $context['end_local']->copy()->startOfDay()->addDay()
        );
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $row = $daily[$dateStr] ?? ['total_orders' => 0, 'total_revenue' => 0];
            $filledData[] = [
                'date' => $dateStr,
                'total_orders' => $row['total_orders'],
                'total_revenue' => $row['total_revenue'],
                'avg_order' => $row['total_orders'] > 0 ? (int) round($row['total_revenue'] / $row['total_orders']) : 0,
            ];
        }

        usort($hours, static fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return response()->json([
            'success' => true,
            'data' => [
                'daily' => $filledData,
                'peak_hours' => array_slice($hours, 0, 5),
                'period' => ['start' => $context['start_local']->toDateString(), 'end' => $context['end_local']->toDateString()],
            ],
            'message' => 'Data grafik berhasil dimuat',
        ]);
    }

    public function shifts(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $context = $this->reportContext($request);
        $slugs = $this->reportScope($request, $org)['slugs'];
        $outletNames = Outlet::query()->whereIn('tenant_slug', $slugs)->pluck('name', 'tenant_slug');
        $periodLogs = PosStaffCashLog::query()
            ->whereIn('tenant_id', $slugs)
            ->where(function ($query) use ($context) {
                $query->where(function ($closed) use ($context) {
                    $closed->where('status', 'closed')
                        ->whereBetween('closed_at', [$context['start_utc'], $context['end_utc']]);
                })->orWhere(function ($open) use ($context) {
                    $open->where('status', 'open')
                        ->whereBetween('started_at', [$context['start_utc'], $context['end_utc']]);
                });
            });
        $closedSummary = (clone $periodLogs)->where('status', 'closed')
            ->selectRaw('
                COUNT(*) as closed_shifts,
                COALESCE(SUM(opening_cash), 0) as opening_cash,
                COALESCE(SUM(total_cash_sales), 0) as cash_sales,
                COALESCE(SUM(expected_cash), 0) as expected_cash,
                COALESCE(SUM(closing_cash), 0) as closing_cash,
                COALESCE(SUM(difference_cash), 0) as difference_cash,
                COALESCE(SUM(total_transactions), 0) as transactions
            ')
            ->first();
        $openShifts = (clone $periodLogs)->where('status', 'open')->count();
        $cashLogs = (clone $periodLogs)
            ->with(['staff:id,name', 'shift:id,title'])
            ->orderByDesc('started_at')
            ->paginate(min(100, max(1, (int) $request->input('per_page', 25))));
        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'closed_shifts' => (int) ($closedSummary->closed_shifts ?? 0),
                    'open_shifts' => $openShifts,
                    'opening_cash' => (int) ($closedSummary->opening_cash ?? 0),
                    'cash_sales' => (int) ($closedSummary->cash_sales ?? 0),
                    'expected_cash' => (int) ($closedSummary->expected_cash ?? 0),
                    'closing_cash' => (int) ($closedSummary->closing_cash ?? 0),
                    'difference_cash' => (int) ($closedSummary->difference_cash ?? 0),
                    'transactions' => (int) ($closedSummary->transactions ?? 0),
                ],
                'shifts' => collect($cashLogs->items())->map(fn (PosStaffCashLog $log) => [
                    'id' => (int) $log->id,
                    'staff_name' => $log->staff?->name ?? 'Staff dihapus',
                    'outlet_name' => $outletNames->get($log->tenant_id),
                    'shift_title' => $log->shift?->title,
                    'status' => (string) $log->status,
                    'opening_cash' => (int) $log->opening_cash,
                    'cash_sales' => (int) $log->total_cash_sales,
                    'expected_cash' => $log->expected_cash === null ? null : (int) $log->expected_cash,
                    'closing_cash' => $log->closing_cash === null ? null : (int) $log->closing_cash,
                    'difference_cash' => $log->difference_cash === null ? null : (int) $log->difference_cash,
                    'transactions' => (int) $log->total_transactions,
                    'started_at' => optional($log->started_at)->toIso8601String(),
                    'closed_at' => optional($log->closed_at)->toIso8601String(),
                    'notes' => $log->notes,
                ])->values(),
                'pagination' => [
                    'current_page' => $cashLogs->currentPage(),
                    'last_page' => $cashLogs->lastPage(),
                    'per_page' => $cashLogs->perPage(),
                    'total' => $cashLogs->total(),
                ],
            ],
            'message' => 'Rekonsiliasi shift berhasil dimuat',
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $context = $this->reportContext($request);
        $slugs = $this->reportScope($request, $org)['slugs'];
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $query = $this->settledOrders($slugs, $context['start_utc'], $context['end_utc'], $context['date_basis'])
            ->with(['items:id,order_id,product_name,qty,unit_price,line_total'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id');

        if ($request->filled('payment_method')) {
            $query->where('payment_method', (string) $request->input('payment_method'));
        }

        $orders = $query->paginate($perPage);
        $orders->getCollection()->transform(fn (Order $order) => [
            'id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'customer_name' => (string) ($order->customer_name ?: 'Pelanggan umum'),
            'payment_status' => (string) $order->payment_status,
            'payment_method' => (string) ($order->payment_method ?: 'unknown'),
            'service_type' => (string) ($order->service_type ?: 'unknown'),
            'created_at' => optional($order->created_at)->toIso8601String(),
            'paid_at' => optional($order->paid_at)->toIso8601String(),
            'report_at' => optional($context['date_basis'] === 'created_at' ? $order->created_at : ($order->paid_at ?? $order->created_at))->toIso8601String(),
            'subtotal_amount' => (int) ($order->subtotal_amount ?: $order->total_amount),
            'discount_amount' => (int) $order->discount_amount + (int) $order->points_discount_amount + (int) $order->manual_discount_amount,
            'service_amount' => (int) $order->service_amount,
            'tax_amount' => (int) $order->tax_amount,
            'final_amount' => (int) ($order->final_amount ?: $order->total_amount),
            'refund_amount' => (int) $order->refund_amount,
            'net_collected' => (int) ($order->final_amount ?: $order->total_amount) - (int) $order->refund_amount,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'product_name' => (string) $item->product_name,
                'quantity' => (int) $item->qty,
                'unit_price' => (int) $item->unit_price,
                'line_total' => (int) $item->line_total,
            ])->values(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'transactions' => $orders->items(),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                ],
            ],
            'message' => 'Rincian transaksi berhasil dimuat',
        ]);
    }

    public function margin(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $context = $this->reportContext($request);
        $slugs = $this->reportScope($request, $org)['slugs'];
        $linkedProducts = HppProduct::query()
            ->where('organization_id', $org->id)
            ->whereNotNull('pos_product_id')
            ->where('is_archived', false)
            ->get(['id', 'pos_product_id', 'cost_full', 'cost_updated_at'])
            ->keyBy('pos_product_id');

        if ($linkedProducts->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => ['coverage_pct' => 0, 'tracked_units' => 0, 'total_units' => 0, 'estimated_profit' => null, 'gross_margin_pct' => null, 'products' => []],
                'message' => 'Belum ada produk POS yang ditautkan ke Kalkulator HPP',
            ]);
        }

        $hppIds = $linkedProducts->pluck('id')->all();
        $snapshots = HppCostSnapshot::query()
            ->whereIn('product_id', $hppIds)
            ->whereBetween('snapshot_date', [$context['start_local']->toDateString(), $context['end_local']->toDateString()])
            ->get(['product_id', 'snapshot_date', 'cost_full']);

        $latestBefore = DB::table('hpp_cost_snapshots as snapshots')
            ->joinSub(
                DB::table('hpp_cost_snapshots')
                    ->selectRaw('product_id, MAX(snapshot_date) as snapshot_date')
                    ->whereIn('product_id', $hppIds)
                    ->where('snapshot_date', '<', $context['start_local']->toDateString())
                    ->groupBy('product_id'),
                'latest',
                fn ($join) => $join->on('snapshots.product_id', '=', 'latest.product_id')
                    ->on('snapshots.snapshot_date', '=', 'latest.snapshot_date')
            )
            ->get(['snapshots.product_id', 'snapshots.snapshot_date', 'snapshots.cost_full']);

        $snapshotMap = $snapshots->concat($latestBefore)
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->sortByDesc(fn ($row) => $row->snapshot_date instanceof Carbon
                ? $row->snapshot_date->toDateString()
                : substr((string) $row->snapshot_date, 0, 10))->values());
        $sales = $this->settledOrders($slugs, $context['start_utc'], $context['end_utc'], $context['date_basis'])
            ->where('payment_status', 'paid')
            ->with(['items:id,order_id,product_id,product_name,qty,line_total'])
            ->orderBy('id');

        $productTotals = [];
        $totalUnits = 0;
        $trackedUnits = 0;
        $sales->chunkById(500, function ($orders) use (&$productTotals, &$totalUnits, &$trackedUnits, $linkedProducts, $snapshotMap, $context): void {
            foreach ($orders as $order) {
                $totalUnits += (int) $order->items->sum('qty');
                $timestamp = $order->paid_at ?? $order->created_at;
                $saleDate = $timestamp->copy()->setTimezone($context['timezone'])->toDateString();
                $subtotal = max(1, (int) ($order->subtotal_amount ?: $order->total_amount));
                $discount = (int) $order->discount_amount + (int) $order->points_discount_amount + (int) $order->manual_discount_amount;

                foreach ($order->items as $item) {
                    $qty = (int) $item->qty;
                    $productId = (int) $item->product_id;
                    $hpp = $linkedProducts->get($productId);
                    $productTotals[$productId] ??= [
                        'product_name' => (string) $item->product_name,
                        'quantity' => 0,
                        'tracked_quantity' => 0,
                        'net_product_sales' => 0,
                        'estimated_cost' => 0,
                    ];
                    $productTotals[$productId]['quantity'] += $qty;
                    if (!$hpp) {
                        continue;
                    }

                    $snapshot = ($snapshotMap->get($hpp->id) ?? collect())
                        ->first(function ($row) use ($saleDate) {
                            $snapshotDate = $row->snapshot_date instanceof Carbon
                                ? $row->snapshot_date->toDateString()
                                : substr((string) $row->snapshot_date, 0, 10);

                            return $snapshotDate <= $saleDate;
                        });
                    $unitCost = $snapshot ? (float) $snapshot->cost_full : (float) $hpp->cost_full;
                    $updatedDate = $hpp->cost_updated_at?->copy()->setTimezone($context['timezone'])->toDateString();
                    $snapshotIsCurrent = $snapshot !== null || ($updatedDate !== null && $updatedDate <= $saleDate);
                    if (!$snapshotIsCurrent || $unitCost <= 0) {
                        continue;
                    }

                    $lineTotal = (int) $item->line_total;
                    $allocatedDiscount = (int) round($discount * $lineTotal / $subtotal);
                    $netLineSales = max(0, $lineTotal - $allocatedDiscount);
                    $productTotals[$productId]['tracked_quantity'] += $qty;
                    $productTotals[$productId]['net_product_sales'] += $netLineSales;
                    $productTotals[$productId]['estimated_cost'] += (int) round($unitCost * $qty);
                    $trackedUnits += $qty;
                }
            }
        });

        $products = collect($productTotals)->map(function (array $row) {
            $profit = $row['net_product_sales'] - $row['estimated_cost'];
            return [
                'product_name' => $row['product_name'],
                'quantity' => $row['quantity'],
                'tracked_quantity' => $row['tracked_quantity'],
                'net_product_sales' => $row['net_product_sales'],
                'estimated_cost' => $row['estimated_cost'],
                'estimated_profit' => $profit,
                'estimated_margin_pct' => $row['net_product_sales'] > 0 ? round($profit / $row['net_product_sales'] * 100, 1) : null,
            ];
        })->sortByDesc('net_product_sales')->values();

        $tracked = $products->filter(fn (array $row) => $row['tracked_quantity'] > 0);
        $salesWithKnownCost = (int) $tracked->sum('net_product_sales');
        $estimatedCost = (int) $tracked->sum('estimated_cost');
        $profit = $salesWithKnownCost - $estimatedCost;

        return response()->json([
            'success' => true,
            'data' => [
                'coverage_pct' => $totalUnits > 0 ? round($trackedUnits / $totalUnits * 100, 1) : 0,
                'tracked_units' => $trackedUnits,
                'total_units' => $totalUnits,
                'net_product_sales' => $salesWithKnownCost,
                'estimated_cost' => $estimatedCost,
                'estimated_profit' => $trackedUnits > 0 ? $profit : null,
                'gross_margin_pct' => $salesWithKnownCost > 0 ? round($profit / $salesWithKnownCost * 100, 1) : null,
                'products' => $products,
                'disclaimer' => 'Estimasi laba setelah HPP tersimpan pada tanggal penjualan dan diskon yang dialokasikan. HPP dapat mencakup overhead teralokasi; estimasi ini belum mengurangi komisi kanal, pajak, service charge, atau biaya lain.',
            ],
            'message' => 'Estimasi margin berhasil dimuat',
        ]);
    }

    private function applyDateRange(Builder $query, string $dateBasis, Carbon $start, Carbon $end, string $table = ''): void
    {
        $prefix = $table === '' ? '' : $table . '.';
        if ($dateBasis === 'created_at') {
            $query->whereBetween($prefix . 'created_at', [$start, $end]);
            return;
        }

        $query->whereBetween(DB::raw("COALESCE({$prefix}paid_at, {$prefix}created_at)"), [$start, $end]);
    }

    public function export(Request $request, PosReportExcelExporter $exporter): BinaryFileResponse
    {
        $org = $this->getOrg($request);
        $context = $this->reportContext($request);
        $scope = $this->reportScope($request, $org);
        $orders = $this->settledOrders($scope['slugs'], $context['start_utc'], $context['end_utc'], $context['date_basis'])
            ->with(['items', 'table'])
            ->orderBy('paid_at')
            ->get();
        $outletNames = Outlet::query()->whereIn('tenant_slug', $scope['slugs'])->pluck('name', 'tenant_slug');
        $shifts = PosStaffCashLog::query()
            ->with(['staff:id,name', 'shift:id,title'])
            ->whereIn('tenant_id', $scope['slugs'])
            ->where(function ($query) use ($context) {
                $query->where(function ($closed) use ($context) {
                    $closed->where('status', 'closed')
                        ->whereBetween('closed_at', [$context['start_utc'], $context['end_utc']]);
                })->orWhere(function ($open) use ($context) {
                    $open->where('status', 'open')
                        ->whereBetween('started_at', [$context['start_utc'], $context['end_utc']]);
                });
            })
            ->orderBy('started_at')
            ->get()
            ->map(fn (PosStaffCashLog $log) => [
                'staff_name' => $log->staff?->name ?? 'Staff dihapus',
                'outlet_name' => $outletNames->get($log->tenant_id) ?? 'Outlet',
                'shift_title' => $log->shift?->title ?? 'Shift',
                'status' => (string) $log->status,
                'opening_cash' => (int) $log->opening_cash,
                'cash_sales' => (int) $log->total_cash_sales,
                'expected_cash' => $log->expected_cash === null ? null : (int) $log->expected_cash,
                'closing_cash' => $log->closing_cash === null ? null : (int) $log->closing_cash,
                'difference_cash' => $log->difference_cash === null ? null : (int) $log->difference_cash,
                'transactions' => (int) $log->total_transactions,
                'started_at' => $log->started_at,
                'closed_at' => $log->closed_at,
                'notes' => $log->notes,
            ]);

        $export = $exporter->export(
            $org,
            $context['start_local'],
            $context['end_local'],
            $orders,
            $scope['aggregate'] ? 'Semua Outlet' : 'Outlet Aktif',
            $context['date_basis'],
            $context['timezone'],
            $shifts
        );

        return response()->download(
            $export['path'],
            $export['filename'],
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }
}
