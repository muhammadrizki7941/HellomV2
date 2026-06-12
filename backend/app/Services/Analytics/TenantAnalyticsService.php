<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;
use App\Models\Tenant;
use App\Models\Order;
use App\Models\Product;
use App\Models\Category;
use App\Models\Reservation;
use App\Services\Cache\TenantCache;
use Carbon\Carbon;

class TenantAnalyticsService
{
    public function __construct(
        private TenantCache $cache
    ) {}

    /**
     * Get analytics for a tenant
     */
    public function getAnalytics(Tenant $tenant, string $period = 'month', bool $force = false): array
    {
        $this->cache->setTenant($this->createTenantContext($tenant));

        $cacheKey = "analytics:{$period}";
        $cached = $this->cache->get($cacheKey);

        if ($cached && !$force) {
            return $cached;
        }

        $dateRange = $this->getDateRange($period);
        $analytics = $this->calculateAnalytics($tenant, $dateRange);

        // Cache for 1 hour
        $this->cache->put($cacheKey, $analytics, 3600);

        return $analytics;
    }

    /**
     * Get analytics summary for all tenants
     */
    public function getAllTenantsSummary(string $period = 'month'): array
    {
        $tenants = Tenant::all();
        $summary = [
            'total_tenants' => $tenants->count(),
            'active_tenants' => $tenants->where('status', 'active')->count(),
            'total_orders' => 0,
            'total_revenue' => 0,
            'avg_orders_per_tenant' => 0,
            'avg_revenue_per_tenant' => 0,
            'top_performing_tenants' => [],
        ];

        $tenantAnalytics = [];

        foreach ($tenants as $tenant) {
            $analytics = $this->getAnalytics($tenant, $period);
            $tenantAnalytics[] = [
                'tenant' => $tenant,
                'analytics' => $analytics,
            ];

            $summary['total_orders'] += $analytics['orders']['total'];
            $summary['total_revenue'] += $analytics['revenue']['total'];
        }

        if ($tenants->count() > 0) {
            $summary['avg_orders_per_tenant'] = round($summary['total_orders'] / $tenants->count(), 1);
            $summary['avg_revenue_per_tenant'] = round($summary['total_revenue'] / $tenants->count(), 2);
        }

        // Sort by revenue and get top 5
        usort($tenantAnalytics, function ($a, $b) {
            return $b['analytics']['revenue']['total'] <=> $a['analytics']['revenue']['total'];
        });

        $summary['top_performing_tenants'] = array_slice($tenantAnalytics, 0, 5);

        return $summary;
    }

    /**
     * Get comparative analytics between two tenants
     */
    public function compareTenants(Tenant $tenant1, Tenant $tenant2, string $period = 'month'): array
    {
        $analytics1 = $this->getAnalytics($tenant1, $period);
        $analytics2 = $this->getAnalytics($tenant2, $period);

        return [
            'tenant1' => [
                'info' => $tenant1,
                'analytics' => $analytics1,
            ],
            'tenant2' => [
                'info' => $tenant2,
                'analytics' => $analytics2,
            ],
            'comparison' => [
                'orders_diff' => $analytics1['orders']['total'] - $analytics2['orders']['total'],
                'revenue_diff' => $analytics1['revenue']['total'] - $analytics2['revenue']['total'],
                'customers_diff' => $analytics1['customers']['unique_customers'] - $analytics2['customers']['unique_customers'],
                'completion_rate_diff' => $analytics1['orders']['completion_rate'] - $analytics2['orders']['completion_rate'],
            ],
        ];
    }

    /**
     * Calculate analytics data (extracted from command)
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
        $totalItems = $orders->with('items')->get()->sum(function ($order) {
            return $order->items->sum('quantity');
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

        return [
            'total' => round($revenue, 2),
            'avg_daily' => round($revenue / max(1, $startDate->diffInDays($endDate) + 1), 2),
        ];
    }

    /**
     * Calculate product metrics
     */
    private function calculateProductMetrics(Tenant $tenant, Carbon $startDate, Carbon $endDate): array
    {
        $totalProducts = Product::where('tenant_id', $tenant->id)->count();
        $activeProducts = Product::where('tenant_id', $tenant->id)->where('is_available', true)->count();

        return [
            'total' => $totalProducts,
            'active' => $activeProducts,
            'inactive' => $totalProducts - $activeProducts,
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

        return [
            'unique_customers' => $uniqueCustomers,
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

        return [
            'total' => $totalReservations,
            'confirmed' => $confirmedReservations,
            'confirmation_rate' => $totalReservations > 0 ? round(($confirmedReservations / $totalReservations) * 100, 1) : 0,
        ];
    }

    /**
     * Calculate performance metrics
     */
    private function calculatePerformanceMetrics(Tenant $tenant, Carbon $startDate, Carbon $endDate): array
    {
        $completedOrders = Order::where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('status', Order::STATUS_COMPLETED)
            ->count();

        $totalOrders = Order::where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        // Calculate average processing time based on created_at to updated_at for completed orders
        $avgProcessingTime = 0;
        if ($completedOrders > 0) {
            $processingTimes = Order::where('tenant_id', $tenant->id)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('status', Order::STATUS_COMPLETED)
                ->get()
                ->map(function ($order) {
                    return $order->created_at->diffInMinutes($order->updated_at);
                });

            $avgProcessingTime = $processingTimes->avg();
        }

        return [
            'avg_processing_time_minutes' => round($avgProcessingTime, 1),
            'completion_rate' => $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 1) : 0,
            'total_completed_orders' => $completedOrders,
            'total_orders' => $totalOrders,
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
     * Create tenant context
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
}