<?php

namespace Tests\Unit\Models;

use App\Models\Plan;
use Tests\TestCase;

class PlanPriceTest extends TestCase
{
    public function test_package_price_does_not_change_with_billing_cycle_or_type(): void
    {
        foreach (['subscription', 'one_time', 'lifetime'] as $type) {
            foreach (['monthly', 'yearly', 'lifetime'] as $cycle) {
                $plan = new Plan(['type' => $type, 'price' => 48000]);
                $this->assertSame(48000, $plan->getEffectivePrice($cycle));
            }
        }
    }
}
