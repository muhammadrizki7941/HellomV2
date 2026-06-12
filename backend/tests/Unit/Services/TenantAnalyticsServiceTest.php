<?php

namespace Tests\Unit\Services;

use App\Services\Analytics\TenantAnalyticsService;
use App\Services\Cache\TenantCache;
use Tests\TestCase;

class TenantAnalyticsServiceTest extends TestCase
{
    private TenantAnalyticsService $analyticsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyticsService = app(TenantAnalyticsService::class);
    }

    public function test_service_can_be_instantiated()
    {
        $this->assertInstanceOf(TenantAnalyticsService::class, $this->analyticsService);
    }

    public function test_get_date_range_returns_correct_ranges()
    {
        // Test month range
        $reflection = new \ReflectionClass($this->analyticsService);
        $method = $reflection->getMethod('getDateRange');
        $method->setAccessible(true);

        $monthRange = $method->invoke($this->analyticsService, 'month');

        $this->assertArrayHasKey('start', $monthRange);
        $this->assertArrayHasKey('end', $monthRange);
        $this->assertEquals(
            now()->startOfMonth()->toDateString(),
            $monthRange['start']->toDateString()
        );
        $this->assertEquals(
            now()->endOfMonth()->toDateString(),
            $monthRange['end']->toDateString()
        );

        // Test week range
        $weekRange = $method->invoke($this->analyticsService, 'week');

        $this->assertArrayHasKey('start', $weekRange);
        $this->assertArrayHasKey('end', $weekRange);
        $this->assertEquals(
            now()->startOfWeek()->toDateString(),
            $weekRange['start']->toDateString()
        );
        $this->assertEquals(
            now()->endOfWeek()->toDateString(),
            $weekRange['end']->toDateString()
        );

        // Test today range
        $todayRange = $method->invoke($this->analyticsService, 'today');

        $this->assertArrayHasKey('start', $todayRange);
        $this->assertArrayHasKey('end', $todayRange);
        $this->assertEquals(
            now()->startOfDay()->toDateString(),
            $todayRange['start']->toDateString()
        );
        $this->assertEquals(
            now()->endOfDay()->toDateString(),
            $todayRange['end']->toDateString()
        );
    }
}