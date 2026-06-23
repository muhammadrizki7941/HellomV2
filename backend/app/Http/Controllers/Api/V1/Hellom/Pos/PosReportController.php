<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Organization;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Services\Pos\PosReportExcelExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
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
            if (empty($slugs)) {
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

    public function summary(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $scope = $this->reportScope($request, $org);
        $slugs = $scope['slugs'];

        // Filter tanggal
        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : Carbon::today()->startOfDay();

        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : Carbon::today()->endOfDay();

        // Base query — hanya order COMPLETED
        $baseQuery = Order::withoutGlobalScope('tenant')
            ->whereIn('tenant_id', $slugs)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate]);

        // Hitung periode sebelumnya untuk perbandingan
        $diffDays = $startDate->diffInDays($endDate) + 1;
        $prevStart = $startDate->copy()->subDays($diffDays)->startOfDay();
        $prevEnd = $startDate->copy()->subDay()->endOfDay();

        $prevQuery = Order::withoutGlobalScope('tenant')
            ->whereIn('tenant_id', $slugs)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$prevStart, $prevEnd]);

        // Data periode ini
        $totalRevenue = $baseQuery->sum('total_amount');
        $totalOrders = $baseQuery->count();
        $avgOrderValue = $totalOrders > 0
            ? round($totalRevenue / $totalOrders) : 0;
        $totalItems = OrderItem::whereHas('order', function($q) use ($slugs, $startDate, $endDate) {
            $q->withoutGlobalScope('tenant')
              ->whereIn('tenant_id', $slugs)
              ->where('status', 'completed')
              ->whereBetween('created_at', [$startDate, $endDate]);
        })->sum('qty');

        // Data periode sebelumnya untuk % perubahan
        $prevRevenue = $prevQuery->sum('total_amount');
        $prevOrders = $prevQuery->count();

        $revenueChange = $prevRevenue > 0
            ? round((($totalRevenue - $prevRevenue) / $prevRevenue) * 100, 1)
            : 0;
        $ordersChange = $prevOrders > 0
            ? round((($totalOrders - $prevOrders) / $prevOrders) * 100, 1)
            : 0;

        // Breakdown per metode pembayaran
        $paymentBreakdown = Order::withoutGlobalScope('tenant')
            ->whereIn('tenant_id', $slugs)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('payment_method, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('payment_method')
            ->get();

        // Breakdown dine_in vs takeaway
        $serviceBreakdown = Order::withoutGlobalScope('tenant')
            ->whereIn('tenant_id', $slugs)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('service_type, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('service_type')
            ->get();

        // Jam tersibuk (peak hours)
        $peakHours = Order::withoutGlobalScope('tenant')
            ->whereIn('tenant_id', $slugs)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('hour')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        // Per-outlet breakdown (owner aggregate view only) — revenue & orders per cabang.
        $outletBreakdown = [];
        if ($scope['aggregate']) {
            $rows = Order::withoutGlobalScope('tenant')
                ->whereIn('tenant_id', $slugs)
                ->where('status', 'completed')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('tenant_id, COUNT(*) as orders_count, SUM(total_amount) as revenue')
                ->groupBy('tenant_id')
                ->get()
                ->keyBy('tenant_id');

            foreach ($scope['outlets'] as $outlet) {
                $row = $rows->get($outlet->tenant_slug);
                $outletBreakdown[] = [
                    'outlet_id'     => $outlet->id,
                    'name'          => $outlet->name,
                    'is_primary'    => (bool) $outlet->is_primary,
                    'total_orders'  => $row ? (int) $row->orders_count : 0,
                    'total_revenue' => $row ? (int) $row->revenue : 0,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'scope'    => $scope['aggregate'] ? 'all_outlets' : 'single_outlet',
                'is_owner' => (bool) $scope['is_owner'],
                'period' => [
                    'start' => $startDate->format('Y-m-d'),
                    'end'   => $endDate->format('Y-m-d'),
                    'days'  => $diffDays,
                ],
                'summary' => [
                    'total_revenue'    => (int) $totalRevenue,
                    'total_orders'     => $totalOrders,
                    'avg_order_value'  => (int) $avgOrderValue,
                    'total_items_sold' => (int) $totalItems,
                    'revenue_change'   => $revenueChange,
                    'orders_change'    => $ordersChange,
                ],
                'payment_breakdown'  => $paymentBreakdown,
                'service_breakdown'  => $serviceBreakdown,
                'peak_hours'         => $peakHours,
                'outlet_breakdown'   => $outletBreakdown,
            ],
            'message' => 'Laporan berhasil dimuat',
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $slugs = $this->reportScope($request, $org)['slugs'];

        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : Carbon::today()->subDays(30)->startOfDay();

        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : Carbon::today()->endOfDay();

        $limit = $request->input('limit', 10);

        // Produk terlaris berdasarkan qty terjual
        $topProducts = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.tenant_id', $slugs)
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->selectRaw('
                order_items.product_name,
                order_items.product_id,
                SUM(order_items.qty) as total_qty,
                SUM(order_items.line_total) as total_revenue,
                AVG(order_items.unit_price) as avg_price,
                COUNT(DISTINCT orders.id) as order_count
            ')
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get();

        // Kategori terlaris
        $topCategories = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('orders.tenant_id', $slugs)
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->selectRaw('
                categories.name as category_name,
                SUM(order_items.qty) as total_qty,
                SUM(order_items.line_total) as total_revenue
            ')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_revenue')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'top_products'   => $topProducts,
                'top_categories' => $topCategories,
                'period' => [
                    'start' => $startDate->format('Y-m-d'),
                    'end'   => $endDate->format('Y-m-d'),
                ],
            ],
            'message' => 'Laporan produk berhasil dimuat',
        ]);
    }

    public function daily(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $slugs = $this->reportScope($request, $org)['slugs'];

        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : Carbon::today()->subDays(29)->startOfDay();

        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : Carbon::today()->endOfDay();

        $dailyData = Order::withoutGlobalScope('tenant')
            ->whereIn('tenant_id', $slugs)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as total_orders,
                SUM(total_amount) as total_revenue,
                AVG(total_amount) as avg_order
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Fill tanggal yang tidak ada order dengan 0
        $period = new \DatePeriod(
            $startDate,
            new \DateInterval('P1D'),
            $endDate->copy()->addDay()
        );

        $filledData = collect($period)->map(function($date) use ($dailyData) {
            $dateStr = $date->format('Y-m-d');
            $found = $dailyData->firstWhere('date', $dateStr);
            return [
                'date'          => $dateStr,
                'total_orders'  => $found ? (int) $found->total_orders : 0,
                'total_revenue' => $found ? (int) $found->total_revenue : 0,
                'avg_order'     => $found ? (int) $found->avg_order : 0,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => ['daily' => $filledData],
            'message' => 'Data grafik berhasil dimuat',
        ]);
    }

    public function export(Request $request, PosReportExcelExporter $exporter): BinaryFileResponse
    {
        $org = $this->getOrg($request);
        $slugs = $this->reportScope($request, $org)['slugs'];

        $startDate = Carbon::parse($request->input('start_date', today()))->startOfDay();
        $endDate = Carbon::parse($request->input('end_date', today()))->endOfDay();

        $orders = Order::withoutGlobalScope('tenant')
            ->whereIn('tenant_id', $slugs)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with(['items', 'table'])
            ->orderBy('created_at')
            ->get();

        $export = $exporter->export($org, $startDate, $endDate, $orders);

        return response()->download(
            $export['path'],
            $export['filename'],
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }
}
