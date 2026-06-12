<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Organization;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Pos\PosReportExcelExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PosReportController extends BasePosController
{

    public function summary(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        // Filter tanggal
        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : Carbon::today()->startOfDay();

        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : Carbon::today()->endOfDay();

        // Base query — hanya order COMPLETED
        $baseQuery = Order::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate]);

        // Hitung periode sebelumnya untuk perbandingan
        $diffDays = $startDate->diffInDays($endDate) + 1;
        $prevStart = $startDate->copy()->subDays($diffDays)->startOfDay();
        $prevEnd = $startDate->copy()->subDay()->endOfDay();

        $prevQuery = Order::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$prevStart, $prevEnd]);

        // Data periode ini
        $totalRevenue = $baseQuery->sum('total_amount');
        $totalOrders = $baseQuery->count();
        $avgOrderValue = $totalOrders > 0
            ? round($totalRevenue / $totalOrders) : 0;
        $totalItems = OrderItem::whereHas('order', function($q) use ($tenantSlug, $startDate, $endDate) {
            $q->withoutGlobalScope('tenant')
              ->where('tenant_id', $tenantSlug)
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
            ->where('tenant_id', $tenantSlug)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('payment_method, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('payment_method')
            ->get();

        // Breakdown dine_in vs takeaway
        $serviceBreakdown = Order::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('service_type, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('service_type')
            ->get();

        // Jam tersibuk (peak hours)
        $peakHours = Order::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('hour')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
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
            ],
            'message' => 'Laporan berhasil dimuat',
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

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
            ->where('orders.tenant_id', $tenantSlug)
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
            ->where('orders.tenant_id', $tenantSlug)
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
        $tenantSlug = $this->getTenantSlug($org);

        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : Carbon::today()->subDays(29)->startOfDay();

        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : Carbon::today()->endOfDay();

        $dailyData = Order::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
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
        $tenantSlug = $this->getTenantSlug($org);

        $startDate = Carbon::parse($request->input('start_date', today()))->startOfDay();
        $endDate = Carbon::parse($request->input('end_date', today()))->endOfDay();

        $orders = Order::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
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
