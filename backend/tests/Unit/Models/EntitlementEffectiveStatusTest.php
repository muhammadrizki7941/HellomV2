<?php

namespace Tests\Unit\Models;

use App\Models\Entitlement;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EntitlementEffectiveStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function entitlement(string $status, ?string $endsAt): Entitlement
    {
        return new Entitlement(['status' => $status, 'ends_at' => $endsAt]);
    }

    public function test_active_without_end_date_allows_access(): void
    {
        $entitlement = $this->entitlement('active', null);

        $this->assertSame('active', $entitlement->effectiveStatus());
        $this->assertTrue($entitlement->allowsAccess());
    }

    public function test_active_past_end_date_is_expired(): void
    {
        Carbon::setTestNow('2027-05-01 12:00:00');
        config(['payments.billing.grace_days' => 0]);

        $entitlement = $this->entitlement('active', '2027-04-30 12:00:00');

        $this->assertSame('expired', $entitlement->effectiveStatus());
        $this->assertFalse($entitlement->allowsAccess());
    }

    public function test_grace_days_extend_access(): void
    {
        Carbon::setTestNow('2027-05-01 12:00:00');
        config(['payments.billing.grace_days' => 3]);

        $this->assertTrue($this->entitlement('active', '2027-04-30 12:00:00')->allowsAccess());
        $this->assertFalse($this->entitlement('active', '2027-04-27 12:00:00')->allowsAccess());
    }

    public function test_locked_status_is_kept(): void
    {
        $entitlement = $this->entitlement('locked', null);

        $this->assertSame('locked', $entitlement->effectiveStatus());
        $this->assertFalse($entitlement->allowsAccess());
    }
}
