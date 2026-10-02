<?php

namespace Tests\Admin;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** P1-6/P1-10/P2-2/P2-3: organization status, access override with a period, audit log, invoices. */
class OrganizationAdminTest extends AdminTestCase
{
    public function test_access_override_needs_an_end_date_or_explicit_lifetime(): void
    {
        ['org' => $org] = $this->makeOrganization('OVR');
        AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        $admin = $this->superAdmin();

        $this->api($admin, 'POST', '/admin/entitlements/override', ['organization_id' => $org->id, 'app_slug' => 'pos', 'status' => 'active'])
            ->assertStatus(422)->assertJsonPath('error.code', 'ENDS_AT_REQUIRED');

        $endsAt = now()->addDays(30)->toDateString();
        $this->api($admin, 'POST', '/admin/entitlements/override', ['organization_id' => $org->id, 'app_slug' => 'pos', 'status' => 'active', 'ends_at' => $endsAt])->assertOk();
        $entitlement = Entitlement::query()->where('organization_id', $org->id)->firstOrFail();
        $this->assertSame('active', $entitlement->status);
        $this->assertSame($endsAt, $entitlement->ends_at->toDateString());

        $this->api($admin, 'POST', '/admin/entitlements/override', ['organization_id' => $org->id, 'app_slug' => 'pos', 'status' => 'active', 'lifetime' => true])->assertOk();
        $this->assertNull($entitlement->fresh()->ends_at);

        // The audit entry belongs to the organization that was changed, not the admin's own.
        $this->assertSame($org->id, (int) DB::table('audit_logs')->where('action', 'entitlement.override')->orderByDesc('id')->value('organization_id'));
    }

    public function test_suspend_blocks_the_app_and_reactivate_restores_it(): void
    {
        ['org' => $org] = $this->makeOrganization('SUS');
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        Entitlement::query()->create(['organization_id' => $org->id, 'app_id' => $app->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $owner = $this->makeUser('admin', $org);
        $admin = $this->superAdmin();

        $this->api($admin, 'POST', "/admin/organizations/{$org->id}/suspend")->assertOk();
        $this->api($owner, 'GET', '/pos/orders')->assertForbidden()->assertJsonPath('error.code', 'ORG_INACTIVE');

        $this->api($admin, 'POST', "/admin/organizations/{$org->id}/reactivate")->assertOk();
        $this->assertSame('active', $org->fresh()->status);

        $logs = $this->api($admin, 'GET', '/admin/audit-logs?action=organization.')->assertOk()->json('data.items');
        $this->assertSame(['organization.reactivate', 'organization.suspend'], array_slice(array_column($logs, 'action'), 0, 2));
        $this->assertSame($org->name, $logs[0]['organization']['name']);
    }

    public function test_invoices_are_paginated_and_searchable(): void
    {
        ['org' => $org] = $this->makeOrganization('INV');
        $prefix = 'INV-T' . Str::upper(Str::random(5));
        foreach (range(1, 3) as $i) {
            DB::table('invoices')->insert(['organization_id' => $org->id, 'invoice_number' => "{$prefix}-{$i}", 'status' => 'paid', 'amount' => 1000, 'tax' => 0, 'total' => 1000,
                'currency' => 'IDR', 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $response = $this->api($this->superAdmin(), 'GET', "/admin/invoices?search={$prefix}&limit=2")->assertOk();
        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertSame($org->name, $response->json('data.items.0.organization.name'));
    }
}
