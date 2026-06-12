<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;
use App\Services\Analytics\TenantAnalyticsService;

class ShowTenantAnalytics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:analytics:show
                            {tenant? : Tenant slug or ID}
                            {--period=month : Analytics period (today, week, month, year)}
                            {--all : Show analytics for all tenants}
                            {--compare= : Compare two tenants (format: tenant1,tenant2)}
                            {--force : Force refresh cached analytics}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display tenant analytics report';

    /**
     * Execute the console command.
     */
    public function handle(TenantAnalyticsService $analyticsService)
    {
        $period = $this->option('period');
        $force = $this->option('force');

        if ($this->option('all')) {
            $this->showAllTenantsAnalytics($analyticsService, $period);
            return;
        }

        if ($compare = $this->option('compare')) {
            $this->showTenantComparison($analyticsService, $compare, $period);
            return;
        }

        $tenantSlug = $this->argument('tenant');

        if (!$tenantSlug) {
            $this->error('Please specify a tenant slug/ID or use --all option');
            return;
        }

        $tenant = $this->findTenant($tenantSlug);

        if (!$tenant) {
            $this->error("Tenant '{$tenantSlug}' not found");
            return;
        }

        $this->showTenantAnalytics($analyticsService, $tenant, $period, $force);
    }

    /**
     * Show analytics for a specific tenant
     */
    private function showTenantAnalytics(TenantAnalyticsService $analyticsService, Tenant $tenant, string $period, bool $force)
    {
        $this->info("Analytics for Tenant: {$tenant->name} ({$tenant->slug})");
        $this->line("Period: {$period}");
        $this->line(str_repeat('=', 60));

        try {
            $analytics = $analyticsService->getAnalytics($tenant, $period, $force);

            $this->displayPeriodInfo($analytics['period']);
            $this->displayOrderMetrics($analytics['orders']);
            $this->displayRevenueMetrics($analytics['revenue']);
            $this->displayProductMetrics($analytics['products']);
            $this->displayCustomerMetrics($analytics['customers']);
            $this->displayReservationMetrics($analytics['reservations']);
            $this->displayPerformanceMetrics($analytics['performance']);

        } catch (\Exception $e) {
            $this->error("Error generating analytics: {$e->getMessage()}");
        }
    }

    /**
     * Show analytics summary for all tenants
     */
    private function showAllTenantsAnalytics(TenantAnalyticsService $analyticsService, string $period)
    {
        $this->info("All Tenants Analytics Summary");
        $this->line("Period: {$period}");
        $this->line(str_repeat('=', 60));

        try {
            $summary = $analyticsService->getAllTenantsSummary($period);

            $this->line("Total Tenants: {$summary['total_tenants']}");
            $this->line("Active Tenants: {$summary['active_tenants']}");
            $this->line("Total Orders: {$summary['total_orders']}");
            $this->line("Total Revenue: $" . number_format($summary['total_revenue'], 2));
            $this->line("Avg Orders per Tenant: {$summary['avg_orders_per_tenant']}");
            $this->line("Avg Revenue per Tenant: $" . number_format($summary['avg_revenue_per_tenant'], 2));
            $this->line('');

            $this->info('Top Performing Tenants:');
            $this->table(
                ['Tenant', 'Orders', 'Revenue', 'Status'],
                collect($summary['top_performing_tenants'])->map(function ($item) {
                    return [
                        $item['tenant']->name,
                        $item['analytics']['orders']['total'],
                        '$' . number_format($item['analytics']['revenue']['total'], 2),
                        $item['tenant']->status,
                    ];
                })->toArray()
            );

        } catch (\Exception $e) {
            $this->error("Error generating analytics: {$e->getMessage()}");
        }
    }

    /**
     * Show comparison between two tenants
     */
    private function showTenantComparison(TenantAnalyticsService $analyticsService, string $compare, string $period)
    {
        $tenants = explode(',', $compare);

        if (count($tenants) !== 2) {
            $this->error('Please provide exactly two tenant slugs/IDs separated by comma');
            return;
        }

        $tenant1 = $this->findTenant(trim($tenants[0]));
        $tenant2 = $this->findTenant(trim($tenants[1]));

        if (!$tenant1 || !$tenant2) {
            $this->error('One or both tenants not found');
            return;
        }

        $this->info("Tenant Comparison: {$tenant1->name} vs {$tenant2->name}");
        $this->line("Period: {$period}");
        $this->line(str_repeat('=', 60));

        try {
            $comparison = $analyticsService->compareTenants($tenant1, $tenant2, $period);

            $this->table(
                ['Metric', $tenant1->name, $tenant2->name, 'Difference'],
                [
                    ['Orders', $comparison['tenant1']['analytics']['orders']['total'], $comparison['tenant2']['analytics']['orders']['total'], $comparison['comparison']['orders_diff']],
                    ['Revenue', '$' . number_format($comparison['tenant1']['analytics']['revenue']['total'], 2), '$' . number_format($comparison['tenant2']['analytics']['revenue']['total'], 2), '$' . number_format($comparison['comparison']['revenue_diff'], 2)],
                    ['Customers', $comparison['tenant1']['analytics']['customers']['unique_customers'], $comparison['tenant2']['analytics']['customers']['unique_customers'], $comparison['comparison']['customers_diff']],
                    ['Completion Rate (%)', $comparison['tenant1']['analytics']['orders']['completion_rate'], $comparison['tenant2']['analytics']['orders']['completion_rate'], number_format($comparison['comparison']['completion_rate_diff'], 1)],
                ]
            );

        } catch (\Exception $e) {
            $this->error("Error generating comparison: {$e->getMessage()}");
        }
    }

    /**
     * Display period information
     */
    private function displayPeriodInfo(array $period)
    {
        $this->line("📅 Period: {$period['start']} to {$period['end']} ({$period['days']} days)");
        $this->line('');
    }

    /**
     * Display order metrics
     */
    private function displayOrderMetrics(array $orders)
    {
        $this->info('📊 Order Metrics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Orders', $orders['total']],
                ['Completed Orders', $orders['completed']],
                ['Pending Orders', $orders['pending']],
                ['Cancelled Orders', $orders['cancelled']],
                ['Completion Rate', $orders['completion_rate'] . '%'],
                ['Avg Order Value', '$' . number_format($orders['avg_order_value'], 2)],
                ['Total Items Ordered', $orders['total_items']],
                ['Avg Items per Order', $orders['avg_items_per_order']],
            ]
        );
        $this->line('');
    }

    /**
     * Display revenue metrics
     */
    private function displayRevenueMetrics(array $revenue)
    {
        $this->info('💰 Revenue Metrics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Revenue', '$' . number_format($revenue['total'], 2)],
                ['Avg Daily Revenue', '$' . number_format($revenue['avg_daily'], 2)],
            ]
        );
        $this->line('');
    }

    /**
     * Display product metrics
     */
    private function displayProductMetrics(array $products)
    {
        $this->info('🍽️ Product Metrics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Products', $products['total']],
                ['Active Products', $products['active']],
                ['Inactive Products', $products['inactive']],
            ]
        );
        $this->line('');
    }

    /**
     * Display customer metrics
     */
    private function displayCustomerMetrics(array $customers)
    {
        $this->info('👥 Customer Metrics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Unique Customers', $customers['unique_customers']],
            ]
        );
        $this->line('');
    }

    /**
     * Display reservation metrics
     */
    private function displayReservationMetrics(array $reservations)
    {
        $this->info('📅 Reservation Metrics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Reservations', $reservations['total']],
                ['Confirmed Reservations', $reservations['confirmed']],
                ['Confirmation Rate', $reservations['confirmation_rate'] . '%'],
            ]
        );
        $this->line('');
    }

    /**
     * Display performance metrics
     */
    private function displayPerformanceMetrics(array $performance)
    {
        $this->info('⚡ Performance Metrics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Avg Processing Time (minutes)', $performance['avg_processing_time_minutes']],
                ['Completion Rate', $performance['completion_rate'] . '%'],
                ['Total Completed Orders', $performance['total_completed_orders']],
                ['Total Orders', $performance['total_orders']],
            ]
        );
        $this->line('');
    }

    /**
     * Find tenant by slug or ID
     */
    private function findTenant(string $identifier): ?Tenant
    {
        return Tenant::where('slug', $identifier)
            ->orWhere('id', $identifier)
            ->first();
    }
}