<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Analytics\TenantAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    public function __construct(
        private TenantAnalyticsService $analyticsService
    ) {}

    /**
     * Get analytics for current tenant
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $period = $request->get('period', 'month');
        $force = $request->boolean('force', false);

        try {
            $analytics = $this->analyticsService->getAnalytics($tenant, $period, $force);

            return response()->json([
                'success' => true,
                'data' => $analytics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate analytics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get analytics summary for all tenants (admin only)
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Tenant::class);

        $period = $request->get('period', 'month');

        try {
            $summary = $this->analyticsService->getAllTenantsSummary($period);

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate analytics summary',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Compare analytics between two tenants (admin only)
     */
    public function compare(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Tenant::class);

        $tenant1Slug = $request->get('tenant1');
        $tenant2Slug = $request->get('tenant2');
        $period = $request->get('period', 'month');

        if (!$tenant1Slug || !$tenant2Slug) {
            return response()->json([
                'success' => false,
                'message' => 'Both tenant1 and tenant2 parameters are required',
            ], 400);
        }

        $tenant1 = Tenant::where('slug', $tenant1Slug)->first();
        $tenant2 = Tenant::where('slug', $tenant2Slug)->first();

        if (!$tenant1 || !$tenant2) {
            return response()->json([
                'success' => false,
                'message' => 'One or both tenants not found',
            ], 404);
        }

        try {
            $comparison = $this->analyticsService->compareTenants($tenant1, $tenant2, $period);

            return response()->json([
                'success' => true,
                'data' => $comparison,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate tenant comparison',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get analytics dashboard data for current tenant
     */
    public function dashboard(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        try {
            $currentMonth = $this->analyticsService->getAnalytics($tenant, 'month');
            $lastMonth = $this->analyticsService->getAnalytics($tenant, 'month', false);

            // Calculate month-over-month changes
            $momChanges = [
                'orders' => $this->calculateChange(
                    $currentMonth['orders']['total'],
                    $lastMonth['orders']['total']
                ),
                'revenue' => $this->calculateChange(
                    $currentMonth['revenue']['total'],
                    $lastMonth['revenue']['total']
                ),
                'customers' => $this->calculateChange(
                    $currentMonth['customers']['unique_customers'],
                    $lastMonth['customers']['unique_customers']
                ),
                'completion_rate' => $this->calculateChange(
                    $currentMonth['orders']['completion_rate'],
                    $lastMonth['orders']['completion_rate']
                ),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'current_month' => $currentMonth,
                    'last_month' => $lastMonth,
                    'mom_changes' => $momChanges,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate dashboard data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate percentage change
     */
    private function calculateChange(float $current, float $previous): array
    {
        if ($previous == 0) {
            return [
                'value' => $current > 0 ? 100 : 0,
                'direction' => $current > 0 ? 'up' : 'neutral',
            ];
        }

        $change = (($current - $previous) / $previous) * 100;

        return [
            'value' => round(abs($change), 1),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral'),
        ];
    }
}