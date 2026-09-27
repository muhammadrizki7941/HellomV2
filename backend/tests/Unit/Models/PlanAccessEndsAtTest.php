<?php

namespace Tests\Unit\Models;

use App\Models\Plan;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlanAccessEndsAtTest extends TestCase
{
    private function plan(array $attributes): Plan
    {
        return new Plan(array_merge(['type' => Plan::TYPE_SUBSCRIPTION, 'billing_cycles' => []], $attributes));
    }

    private function start(): Carbon
    {
        return Carbon::parse('2026-01-31 10:00:00');
    }

    public function test_lifetime_and_free_plans_never_end(): void
    {
        $this->assertNull($this->plan(['type' => Plan::TYPE_LIFETIME])->accessEndsAt($this->start(), 'lifetime'));
        $this->assertNull($this->plan(['type' => Plan::TYPE_FREE])->accessEndsAt($this->start()));
    }

    public function test_duration_days_wins_over_cycle(): void
    {
        $plan = $this->plan(['type' => Plan::TYPE_ONE_TIME, 'duration_days' => 365, 'billing_cycles' => ['yearly']]);

        $this->assertSame('2027-01-31 10:00:00', $plan->accessEndsAt($this->start(), 'yearly')->toDateTimeString());
    }

    // Note: monthly uses Carbon::addMonth(), which overflows (Jan 31 + 1 month =
    // Mar 3). This matches the behaviour before accessEndsAt() existed.
    public function test_chosen_cycle_wins_over_supported_cycles(): void
    {
        $plan = $this->plan(['billing_cycles' => ['monthly', 'yearly']]);

        $this->assertSame('2026-03-03 10:00:00', $plan->accessEndsAt($this->start(), 'monthly')->toDateTimeString());
        $this->assertSame('2027-01-31 10:00:00', $plan->accessEndsAt($this->start(), 'yearly')->toDateTimeString());
    }

    public function test_without_chosen_cycle_falls_back_to_plan_cycles(): void
    {
        $this->assertSame('2027-01-31 10:00:00', $this->plan(['billing_cycles' => ['yearly']])->accessEndsAt($this->start())->toDateTimeString());
        $this->assertSame('2026-03-03 10:00:00', $this->plan(['billing_cycles' => ['monthly']])->accessEndsAt($this->start())->toDateTimeString());
    }

    public function test_does_not_mutate_the_start_date(): void
    {
        $start = $this->start();
        $this->plan(['billing_cycles' => ['monthly']])->accessEndsAt($start, 'monthly');

        $this->assertSame('2026-01-31 10:00:00', $start->toDateTimeString());
    }
}
