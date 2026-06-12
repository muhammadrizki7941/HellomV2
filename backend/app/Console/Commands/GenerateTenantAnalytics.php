<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;
use App\Models\Order;
use App\Models\Product;
use App\Models\Category;
use App\Models\Reservation;
use App\Services\Cache\TenantCache;
use Carbon\Carbon;

class GenerateTenantAnalytics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analytics:generate {--tenant= : Specific tenant slug} {--period=today : Period (today, week, month, year)} {--force : Force regeneration even if cached}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate comprehensive analytics data for tenants';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenantSlug = $this->option('tenant');
        $period = $this->option('period');
        $force = $this->option('force');

        $this->info('📊 Generating Tenant Analytics');
        $this->newLine();

        // Validate period
        $validPeriods = ['today', 'week', 'month', 'year'];
        if (!in_array($period, $validPeriods)) {
            $this->error("Invalid period. Choose from: " . implode(', ', $validPeriods));
            return 1;
        }

        if ($tenantSlug) {
            // Generate for specific tenant
            $tenant = Tenant::where('slug', $tenantSlug)->first();
            if (!$tenant) {
                $this->error("Tenant '{$tenantSlug}' not found.");
                return 1;
            }

            $this->generateTenantAnalytics($tenant, $period, $force);
        } else {
            // Generate for all tenants
            $tenants = Tenant::all();
            $this->info("Generating analytics for {$tenants->count()} tenants...");

            $progressBar = $this->output->createProgressBar($tenants->count());
            $progressBar->start();

            foreach ($tenants as $tenant) {
                $this->generateTenantAnalytics($tenant, $period, $force);
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);
            $this->info('✅ All tenant analytics generated successfully!');
        }

        return 0;
    }

    /**
     * Generate analytics for a specific tenant
     */
    private function generateTenantAnalytics(Tenant $tenant, string $period, bool $force): void
    {
        $cache = app(TenantCache::class);
        $cache->setTenant($this->createTenantContext($tenant));

        $cacheKey = "analytics:{$period}";
        $cached = $cache->get($cacheKey);

        if ($cached && !$force) {
            $this->line("⏭️  Skipping {$tenant->name} ({$period}) - already cached");
            return;
        }

        $dateRange = $this->getDateRange($period);
        $analytics = $this->calculateAnalytics($tenant, $dateRange);

        // Cache for 1 hour
        $cache->put($cacheKey, $analytics, 3600);

        $this->displayAnalytics($tenant, $analytics, $period);
    }

    /**
     * Calculate analytics data
     */
    private function calculateAnalytics(Tenant $tenant, array $dateRange): array
    {
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];

        return [
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
                'days' => $startDate->diffInDays($endDate) + 1,
            ],
            'orders' => $this->calculateOrderMetrics($tenant, $startDate, $endDate),
            'revenue' => $this->calculateRevenueMetrics($tenant, $startDate, $endDate),
            'products' => $this->calculateProductMetrics($tenant, $startDate, $endDate),
            'customers' => $this->calculateCustomerMetrics($tenant, $startDate, $endDate),
            'reservations' => $this->calculateReservationMetrics($tenant, $startDate, $endDate),
            'performance' => $this->calculatePerformanceMetrics($tenant, $startDate, $endDate),
        ];
    }

    /**
     * Calculate order metrics
     */
    private function calculateOrderMetrics(Tenant $tenant, Carbon $startDate, Carbon $endDate): array
    {
        $orders = Order::where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$startDate, $endDate]);

        $totalOrders = $orders->count();
        $completedOrders = (clone $orders)->where('status', Order::STATUS_COMPLETED)->count();
        $pendingOrders = (clone $orders)->whereIn('status', [Order::STATUS_NEW, Order::STATUS_ACCEPTED, Order::STATUS_PREPARING])->count();
        $cancelledOrders = (clone $orders)->where('status', Order::STATUS_CANCELLED)->count();

        $avgOrderValue = $orders->avg('total_amount') ?? 0;
        $totalItems = $orders->with('orderItems')->get()->sum(function ($order) {
            return $order->orderItems->sum('quantity');
        });

        return [
            'total' => $totalOrders,
            'completed' => $completedOrders,
            'pending' => $pendingOrders,
            'cancelled' => $cancelledOrders,
            'completion_rate' => $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 1) : 0,
            'avg_order_value' => round($avgOrderValue, 2),
            'total_items' => $totalItems,
            'avg_items_per_order' => $totalOrders > 0 ? round($totalItems / $totalOrders, 1) : 0,
        ];
    }

    /**
     * Calculate revenue metrics
     */
    private function calculateRevenueMetrics(Tenant $tenant, Carbon $startDate, Carbon $endDate): array
    {
        $revenue = Order::where('tenant_id', $tenant->id)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('total_amount');

        $dailyRevenue = Order::where('tenant_id', $tenant->id)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, SUM(total_amount) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $avgDailyRevenue = $dailyRevenue->avg('revenue') ?? 0;

        return [
            'total' => round($revenue, 2),
            'avg_daily' => round($avgDailyRevenue, 2),
            'daily_breakdown' => $dailyRevenue->toArray(),
        ];
    }

    /**
     * Calculate product metrics
     */
    private function calculateProductMetrics(Tenant $tenant, Carbon $startDate, Carbon $endDate): array
    {
        $totalProducts = Product::where('tenant_id', $tenant->id)->count();
        $activeProducts = Product::where('tenant_id', $tenant->id)->where('is_available', true)->count();

        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.tenant_id', $tenant->id)
            ->where('orders.status', Order::STATUS_COMPLETED)
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->selectRaw('products.name, SUM(order_items.quantity) as total_quantity, SUM(order_items.total_price) as total_revenue')
            ->groupBy('products.id', 'products.name')
            ->orderBy('total_quantity', 'desc')
            ->limit(5)
            ->get();

        return [
            'total' => $totalProducts,
            'active' => $activeProducts,
            'inactive' => $totalProducts - $activeProducts,
            'top_products' => $topProducts->toArray(),
        ];
    }

    /**
     * Calculate customer metrics
     */
    private function calculateCustomerMetrics(Tenant $tenant, Carbon $startDate, Carbon $endDate): array
    {
        $orders = Order::where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$startDate, $endDate]);

        $uniqueCustomers = $orders->distinct('customer_name')->count('customer_name');
        $returningCustomers = $orders->select('customer_name')
            ->groupBy('customer_name')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $newVsReturning = [
            'new' => $uniqueCustomers - $returningCustomers,
            'returning' => $returningCustomers,
        ];

        return [
            'unique_customers' => $uniqueCustomers,
            'new_vs_returning' => $newVsReturning,
            'retention_rate' => $uniqueCustomers > 0 ? round(($returningCustomers / $uniqueCustomers) * 100, 1) : 0,
        ];
    }

    /**
     * Calculate reservation metrics
     */
    private function calculateReservationMetrics(Tenant $tenant, Carbon $startDate, Carbon $endDate): array
    {
        $reservations = Reservation::where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$startDate, $endDate]);

        $totalReservations = $reservations->count();
        $confirmedReservations = (clone $reservations)->where('status', 'confirmed')->count();
        $pendingReservations = (clone $reservations)->where('status', 'pending')->count();
        $cancelledReservations = (clone $reservations)->where('status', 'cancelled')->count();

        return [
            'total' => $totalReservations,
            'confirmed' => $confirmedReservations,
            'pending' => $pendingReservations,
            'cancelled' => $cancelledReservations,
            'confirmation_rate' => $totalReservations > 0 ? round(($confirmedReservations / $totalReservations) * 100, 1) : 0,
        ];
    }

    /**
     * Calculate performance metrics
     */
    private function calculatePerformanceMetrics(Tenant $tenant, Carbon $startDate, Carbon $endDate): array
    {
        $orders = Order::where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $avgPrepTime = 0;
        $onTimeDelivery = 0;
        $totalCompleted = 0;

        foreach ($orders as $order) {
            if ($order->status === Order::STATUS_COMPLETED && $order->accepted_at && $order->completed_at) {
                $prepTime = $order->accepted_at->diffInMinutes($order->completed_at);
                $avgPrepTime += $prepTime;
                $totalCompleted++;

                // Assume target prep time is 30 minutes
                if ($prepTime <= 30) {
                    $onTimeDelivery++;
                }
            }
        }

        if ($totalCompleted > 0) {
            $avgPrepTime = round($avgPrepTime / $totalCompleted, 1);
            $onTimeRate = round(($onTimeDelivery / $totalCompleted) * 100, 1);
        } else {
            $onTimeRate = 0;
        }

        return [
            'avg_prep_time_minutes' => $avgPrepTime,
            'on_time_delivery_rate' => $onTimeRate,
            'total_completed_orders' => $totalCompleted,
        ];
    }

    /**
     * Get date range for period
     */
    private function getDateRange(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            'today' => [
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            'week' => [
                'start' => $now->copy()->startOfWeek(),
                'end' => $now->copy()->endOfWeek(),
            ],
            'month' => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth(),
            ],
            'year' => [
                'start' => $now->copy()->startOfYear(),
                'end' => $now->copy()->endOfYear(),
            ],
        };
    }

    /**
     * Create tenant context for analytics
     */
    private function createTenantContext(Tenant $tenant): \App\Services\Tenancy\TenantContext
    {
        return new \App\Services\Tenancy\TenantContext(
            id: $tenant->id,
            slug: $tenant->slug,
            name: $tenant->name,
            plan: $tenant->plan,
            status: $tenant->status,
            trialStartedAt: $tenant->trial_started_at,
            activeUntil: $tenant->active_until,
            subdomain: $tenant->subdomain,
            customDomain: $tenant->custom_domain
        );
    }

    /**
     * Display analytics results
     */
    private function displayAnalytics(Tenant $tenant, array $analytics, string $period): void
    {
        $this->newLine();
        $this->line("📈 <comment>{$tenant->name}</comment> Analytics ({$period})");
        $this->line("Period: {$analytics['period']['start']} to {$analytics['period']['end']} ({$analytics['period']['days']} days)");
        $this->newLine();

        // Orders
        $this->line("🛒 <info>Orders</info>");
        $orders = $analytics['orders'];
        $this->line("  Total: {$orders['total']} | Completed: {$orders['completed']} | Pending: {$orders['pending']} | Cancelled: {$orders['cancelled']}");
        $this->line("  Completion Rate: {$orders['completion_rate']}% | Avg Order Value: Rp{$orders['avg_order_value']}");
        $this->line("  Total Items: {$orders['total_items']} | Avg Items/Order: {$orders['avg_items_per_order']}");

        // Revenue
        $this->line("💰 <info>Revenue</info>");
        $revenue = $analytics['revenue'];
        $this->line("  Total: Rp" . number_format($revenue['total'], 0, ',', '.'));
        $this->line("  Avg Daily: Rp" . number_format($revenue['avg_daily'], 0, ',', '.'));

        // Products
        $this->line("📦 <info>Products</info>");
        $products = $analytics['products'];
        $this->line("  Total: {$products['total']} | Active: {$products['active']} | Inactive: {$products['inactive']}");

        // Customers
        $this->line("👥 <info>Customers</info>");
        $customers = $analytics['customers'];
        $this->line("  Unique: {$customers['unique_customers']} | Retention Rate: {$customers['retention_rate']}%");

        // Reservations
        $this->line("📅 <info>Reservations</info>");
        $reservations = $analytics['reservations'];
        $this->line("  Total: {$reservations['total']} | Confirmed: {$reservations['confirmed']} | Confirmation Rate: {$reservations['confirmation_rate']}%");

        // Performance
        $this->line("⚡ <info>Performance</info>");
        $performance = $analytics['performance'];
        $this->line("  Avg Prep Time: {$performance['avg_prep_time_minutes']}min | On-Time Delivery: {$performance['on_time_delivery_rate']}%");

        $this->line("✅ Cached for 1 hour");
    }
}
