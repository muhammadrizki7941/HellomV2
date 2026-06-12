<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XLSXWriter;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        [$start, $end, $period] = $this->resolveRange($request);

        // Only completed orders/reservations count as revenue.
        // We treat updated_at as "completed time" because status update to completed touches updated_at.
        $ordersQuery = Order::query()
            ->where('status', Order::STATUS_COMPLETED)
            ->whereBetween('updated_at', [$start, $end]);

        $reservationsQuery = Reservation::query()
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$start, $end]);

        $ordersSummary = $ordersQuery
            ->clone()
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(total_amount),0) as revenue_net')
            ->selectRaw('COALESCE(SUM(discount_amount),0) as discount_total')
            ->selectRaw('COALESCE(SUM(redeemed_points),0) as redeemed_points_total')
            ->first();

        $ordersCount = (int) ($ordersSummary->orders_count ?? 0);
        $ordersRevenueNet = (int) ($ordersSummary->revenue_net ?? 0);
        $discountTotal = (int) ($ordersSummary->discount_total ?? 0);

        $resSummary = $reservationsQuery
            ->clone()
            ->selectRaw('COUNT(*) as reservations_count')
            ->selectRaw('COALESCE(SUM(total_price),0) as reservations_revenue')
            ->first();

        $reservationsCount = (int) ($resSummary->reservations_count ?? 0);
        $reservationsRevenue = (int) ($resSummary->reservations_revenue ?? 0);

        $transactionsCount = $ordersCount + $reservationsCount;
        $totalNet = $ordersRevenueNet + $reservationsRevenue;

        $summaryView = [
            'orders_count' => $ordersCount,
            'reservations_count' => $reservationsCount,

            'revenue_net' => $ordersRevenueNet,
            'discount_total' => $discountTotal,
            'revenue_gross' => $ordersRevenueNet + $discountTotal,

            'reservations_revenue' => $reservationsRevenue,
            'total_net' => $totalNet,

            'redeemed_points_total' => (int) ($ordersSummary->redeemed_points_total ?? 0),
            'avg_order' => $ordersCount > 0 ? (int) floor($ordersRevenueNet / $ordersCount) : 0,
            'avg_transaction' => $transactionsCount > 0 ? (int) floor($totalNet / $transactionsCount) : 0,
        ];

        $dailyOrders = $ordersQuery
            ->clone()
            ->selectRaw('DATE(updated_at) as d')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(total_amount),0) as revenue_net')
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        $dailyReservations = $reservationsQuery
            ->clone()
            ->selectRaw('DATE(updated_at) as d')
            ->selectRaw('COUNT(*) as reservations_count')
            ->selectRaw('COALESCE(SUM(total_price),0) as reservations_revenue')
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        $ordersByDate = $dailyOrders->keyBy('d');
        $resByDate = $dailyReservations->keyBy('d');

        $allDates = collect($ordersByDate->keys())
            ->merge($resByDate->keys())
            ->unique()
            ->sort()
            ->values();

        $chart = [
            'labels' => $allDates->map(fn ($d) => Carbon::parse($d)->format('d M'))->all(),
            'net' => $allDates->map(function ($d) use ($ordersByDate, $resByDate) {
                $o = $ordersByDate->get($d);
                $r = $resByDate->get($d);
                return (int) ($o->revenue_net ?? 0) + (int) ($r->reservations_revenue ?? 0);
            })->all(),
            'orders' => $allDates->map(fn ($d) => (int) (($ordersByDate->get($d)->orders_count ?? 0)))->all(),
        ];

        $productsRange = $this->topProductsForRange($start, $end, 30);
        $segmentsRange = $this->segmentProducts($productsRange);

        [$tStart, $tEnd] = $this->rangeToday();
        [$mStart, $mEnd] = $this->rangeThisMonth();
        [$yStart, $yEnd] = $this->rangeThisYear();

        $topToday = $this->topProductsForRange($tStart, $tEnd, 10);
        $topMonth = $this->topProductsForRange($mStart, $mEnd, 10);
        $topYear = $this->topProductsForRange($yStart, $yEnd, 10);

        return view('admin.reports.index', [
            'period' => $period,
            'start' => $start,
            'end' => $end,
            'summary' => $summaryView,
            'chart' => $chart,
            'productsRange' => $productsRange,
            'segmentsRange' => $segmentsRange,
            'topToday' => $topToday,
            'topMonth' => $topMonth,
            'topYear' => $topYear,
        ]);
    }

    public function exportSales(Request $request)
    {
        [$start, $end] = $this->resolveRange($request);

        $filename = 'laporan-penjualan-'.$start->format('Ymd').'-'.$end->format('Ymd').'.xlsx';

        $orders = Order::query()
            ->where('status', Order::STATUS_COMPLETED)
            ->whereBetween('updated_at', [$start, $end])
            ->orderBy('updated_at');

        $reservations = Reservation::query()
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$start, $end])
            ->orderBy('updated_at');

        return response()->streamDownload(function () use ($orders, $reservations, $start, $end) {
            $writer = new XLSXWriter();
            $writer->openToFile('php://output');

            // Orders sheet
            $writer->getCurrentSheet()->setName('Orders');

            $writer->addRow(Row::fromValues([
                'Completed At',
                'Order Number',
                'Table',
                'Customer',
                'Gross',
                'Discount',
                'Net',
                'Redeemed Points',
                'Payment Status',
            ]));

            foreach ($orders->cursor() as $o) {
                $discount = (int) ($o->discount_amount ?? 0);
                $net = (int) ($o->total_amount ?? 0);
                $gross = $net + $discount;

                $writer->addRow(Row::fromValues([
                    optional($o->updated_at)->format('Y-m-d H:i:s'),
                    (string) $o->order_number,
                    (string) ($o->table_label ?? ''),
                    (string) ($o->customer_name ?? ''),
                    $gross,
                    $discount,
                    $net,
                    (int) ($o->redeemed_points ?? 0),
                    (string) ($o->payment_status ?? ''),
                ]));
            }

            // Reservations sheet
            $writer->addNewSheetAndMakeItCurrent();
            $writer->getCurrentSheet()->setName('Reservations');

            $writer->addRow(Row::fromValues([
                'Completed At',
                'Reservation ID',
                'Space',
                'Customer',
                'Phone',
                'Scheduled At',
                'Total',
            ]));

            foreach ($reservations->cursor() as $r) {
                $writer->addRow(Row::fromValues([
                    optional($r->updated_at)->format('Y-m-d H:i:s'),
                    (string) $r->id,
                    (string) ($r->space_name ?? ''),
                    (string) ($r->customer_name ?? ''),
                    (string) ($r->customer_phone ?? ''),
                    optional($r->scheduled_at)->format('Y-m-d H:i:s'),
                    (int) ($r->total_price ?? 0),
                ]));
            }

            // Summary sheet
            $writer->addNewSheetAndMakeItCurrent();
            $writer->getCurrentSheet()->setName('Summary');

            $sumOrders = Order::query()
                ->where('status', Order::STATUS_COMPLETED)
                ->whereBetween('updated_at', [$start, $end])
                ->selectRaw('COUNT(*) as orders_count')
                ->selectRaw('COALESCE(SUM(total_amount),0) as revenue_net')
                ->selectRaw('COALESCE(SUM(discount_amount),0) as discount_total')
                ->selectRaw('COALESCE(SUM(redeemed_points),0) as redeemed_points_total')
                ->first();

            $sumReservations = Reservation::query()
                ->where('status', 'completed')
                ->whereBetween('updated_at', [$start, $end])
                ->selectRaw('COUNT(*) as reservations_count')
                ->selectRaw('COALESCE(SUM(total_price),0) as reservations_revenue')
                ->first();

            $ordersCount = (int) ($sumOrders->orders_count ?? 0);
            $net = (int) ($sumOrders->revenue_net ?? 0);
            $discount = (int) ($sumOrders->discount_total ?? 0);
            $reservationsCount = (int) ($sumReservations->reservations_count ?? 0);
            $reservationsRevenue = (int) ($sumReservations->reservations_revenue ?? 0);

            $writer->addRow(Row::fromValues(['Range Start', $start->format('Y-m-d')])) ;
            $writer->addRow(Row::fromValues(['Range End', $end->format('Y-m-d')])) ;
            $writer->addRow(Row::fromValues(['Orders Count', $ordersCount])) ;
            $writer->addRow(Row::fromValues(['Reservations Count', $reservationsCount])) ;
            $writer->addRow(Row::fromValues(['Gross Revenue (Orders)', $net + $discount])) ;
            $writer->addRow(Row::fromValues(['Discount Total (Orders)', $discount])) ;
            $writer->addRow(Row::fromValues(['Net Revenue (Orders)', $net])) ;
            $writer->addRow(Row::fromValues(['Revenue (Reservations)', $reservationsRevenue])) ;
            $writer->addRow(Row::fromValues(['Total Net (Orders + Reservations)', $net + $reservationsRevenue])) ;
            $writer->addRow(Row::fromValues(['Redeemed Points', (int) ($sumOrders->redeemed_points_total ?? 0)])) ;

            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportProducts(Request $request)
    {
        [$start, $end] = $this->resolveRange($request);

        $filename = 'laporan-produk-'.$start->format('Ymd').'-'.$end->format('Ymd').'.xlsx';

        $rows = $this->topProductsForRange($start, $end, 500);

        return response()->streamDownload(function () use ($rows, $start, $end) {
            $writer = new XLSXWriter();
            $writer->openToFile('php://output');

            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Products');

            $writer->addRow(Row::fromValues([
                'Product',
                'Qty Sold',
                'Revenue (Gross)',
                'Orders Count',
            ]));

            foreach ($rows as $r) {
                $writer->addRow(Row::fromValues([
                    (string) ($r['product_name'] ?? ''),
                    (int) ($r['qty'] ?? 0),
                    (int) ($r['revenue_gross'] ?? 0),
                    (int) ($r['orders_count'] ?? 0),
                ]));
            }

            $writer->addNewSheetAndMakeItCurrent();
            $writer->getCurrentSheet()->setName('Info');
            $writer->addRow(Row::fromValues(['Range Start', $start->format('Y-m-d')])) ;
            $writer->addRow(Row::fromValues(['Range End', $end->format('Y-m-d')])) ;

            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function resolveRange(Request $request): array
    {
        $period = (string) $request->query('period', 'today');

        // Backward compatibility
        if ($period === 'custom') {
            $period = 'range';
        }

        if ($period === 'date') {
            $date = (string) $request->query('date', '');
            $d = $date !== '' ? Carbon::parse($date) : now();
            return [$d->copy()->startOfDay(), $d->copy()->endOfDay(), 'date'];
        }

        if ($period === 'month') {
            $month = (string) $request->query('month', ''); // YYYY-MM
            $d = $month !== '' ? Carbon::parse($month.'-01') : now();
            return [$d->copy()->startOfMonth()->startOfDay(), $d->copy()->endOfMonth()->endOfDay(), 'month'];
        }

        if ($period === 'year') {
            $year = (string) $request->query('year', ''); // YYYY
            $y = $year !== '' ? (int) $year : (int) now()->format('Y');
            $d = Carbon::create($y, 1, 1, 0, 0, 0);
            return [$d->copy()->startOfYear()->startOfDay(), $d->copy()->endOfYear()->endOfDay(), 'year'];
        }

        if ($period === 'range') {
            $start = $request->query('start');
            $end = $request->query('end');
            $startC = $start ? Carbon::parse($start)->startOfDay() : now()->startOfDay();
            $endC = $end ? Carbon::parse($end)->endOfDay() : now()->endOfDay();
            return [$startC, $endC, 'range'];
        }

        // Default: today
        [$s, $e] = $this->rangeToday();
        return [$s, $e, 'today'];
    }

    private function rangeToday(): array
    {
        return [now()->startOfDay(), now()->endOfDay()];
    }

    private function rangeThisMonth(): array
    {
        return [now()->startOfMonth()->startOfDay(), now()->endOfMonth()->endOfDay()];
    }

    private function rangeThisYear(): array
    {
        return [now()->startOfYear()->startOfDay(), now()->endOfYear()->endOfDay()];
    }

    /**
     * @return array<int, array{product_id:int|null, product_name:string, qty:int, revenue_gross:int, orders_count:int}>
     */
    private function topProductsForRange(Carbon $start, Carbon $end, int $limit): array
    {
        $rowsQuery = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', Order::STATUS_COMPLETED)
            ->whereBetween('orders.updated_at', [$start, $end])
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->selectRaw('order_items.product_id as product_id')
            ->selectRaw('order_items.product_name as product_name')
            ->selectRaw('COALESCE(SUM(order_items.qty),0) as qty')
            ->selectRaw('COALESCE(SUM(order_items.line_total),0) as revenue_gross')
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders_count')
            ->orderByDesc('qty')
            ->limit(max(1, $limit));

        $rows = $rowsQuery->get();

        return $rows
            ->map(fn ($r) => [
                'product_id' => $r->product_id !== null ? (int) $r->product_id : null,
                'product_name' => (string) ($r->product_name ?? ''),
                'qty' => (int) ($r->qty ?? 0),
                'revenue_gross' => (int) ($r->revenue_gross ?? 0),
                'orders_count' => (int) ($r->orders_count ?? 0),
            ])
            ->all();
    }

    /**
     * Segmentasi: banyak disukai (top 20%), menengah (middle 60%), jarang (bottom 20%).
     */
    private function segmentProducts(array $rows): array
    {
        $n = count($rows);
        if ($n < 1) {
            return ['popular' => [], 'medium' => [], 'rare' => []];
        }

        $popularCount = max(3, (int) ceil($n * 0.2));
        $rareCount = max(3, (int) ceil($n * 0.2));
        if (($popularCount + $rareCount) > $n) {
            $popularCount = max(1, (int) floor($n / 2));
            $rareCount = $n - $popularCount;
        }

        $popular = array_slice($rows, 0, $popularCount);
        $rare = array_slice($rows, max(0, $n - $rareCount));
        $medium = array_slice($rows, $popularCount, max(0, $n - $popularCount - $rareCount));

        return [
            'popular' => $popular,
            'medium' => $medium,
            'rare' => $rare,
        ];
    }
}
