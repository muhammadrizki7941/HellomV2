<?php

namespace Tests\Admin;

/**
 * P0-1: users.role "admin" is what every self-registered owner gets; it must not open
 * other organizations (list, switch, team, wallet, payout profile, settings).
 */
class OrganizationIsolationTest extends AdminTestCase
{
    public function test_owner_only_sees_and_switches_to_own_organizations(): void
    {
        ['org' => $own] = $this->makeOrganization('OWN');
        ['org' => $second] = $this->makeOrganization('SECOND');
        ['org' => $foreign] = $this->makeOrganization('FOREIGN');
        $owner = $this->makeUser('admin', $own);
        $second->users()->attach($owner->id, ['role' => 'owner']);

        $ids = collect($this->api($owner, 'GET', '/organizations')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$own->id, $second->id], $ids);

        $this->api($owner, 'POST', '/organizations/switch', ['organization_id' => $foreign->id])->assertNotFound();
        $this->assertSame($own->id, (int) $owner->fresh()->current_organization_id);

        $this->api($owner, 'POST', '/organizations/switch', ['organization_id' => $second->id])->assertOk();
        $this->assertSame($second->id, (int) $owner->fresh()->current_organization_id);
    }

    public function test_foreign_current_organization_grants_nothing(): void
    {
        ['org' => $own] = $this->makeOrganization('OWN');
        ['org' => $foreign] = $this->makeOrganization('FOREIGN');
        $owner = $this->makeUser('admin', $own);
        // State left behind by the old switch endpoint.
        $owner->forceFill(['current_organization_id' => $foreign->id])->save();

        $this->api($owner, 'GET', '/organizations/current/team')->assertNotFound();
        $this->api($owner, 'GET', '/wallet/overview')->assertNotFound();
        $this->api($owner, 'GET', '/payout-profile')->assertNotFound();
        $this->api($owner, 'POST', '/organizations/current/settings', ['name' => 'Diambil alih'])->assertForbidden();
        $this->assertNotSame('Diambil alih', $foreign->fresh()->name);
    }

    public function test_only_owner_or_admin_member_changes_organization_settings(): void
    {
        ['org' => $org] = $this->makeOrganization('SET');
        $member = $this->makeUser('member', $org, 'member');
        $owner = $this->makeUser('admin', $org, 'owner');

        $this->api($member, 'POST', '/organizations/current/settings', ['name' => 'Oleh anggota'])->assertForbidden();
        $this->api($owner, 'POST', '/organizations/current/settings', ['name' => 'Oleh pemilik'])->assertOk();
        $this->assertSame('Oleh pemilik', $org->fresh()->name);
    }
}
