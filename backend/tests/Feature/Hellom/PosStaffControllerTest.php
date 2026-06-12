<?php

namespace Tests\Feature\Hellom;

use App\Models\Organization;
use App\Models\PosStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosStaffControllerTest extends TestCase
{
    use RefreshDatabase;
    use HellomTestHelpers;

    public function test_staff_dashboard_returns_unique_attendance_qr_payload(): void
    {
        [$user, $token] = $this->createHellomUser();
        $organization = $this->createOrganizationContext($user);

        PosStaff::query()->create([
            'tenant_id' => $organization->pos_tenant_slug,
            'organization_id' => $organization->id,
            'name' => 'Raka Admin',
            'role' => 'cashier',
            'employment_status' => 'active',
            'permissions' => ['transactions' => true, 'reports' => false, 'products' => false, 'orders' => true, 'cash_control' => true],
        ]);

        $response = $this->getJson('/api/v1/hellom/pos/staff', $this->hellomHeaders($token));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.staff.0.attendance_qr.payload.type', 'pos_staff_attendance')
            ->assertJsonPath('data.staff.0.attendance_qr.payload.tenant', $organization->pos_tenant_slug);

        $this->assertNotEmpty($response->json('data.staff.0.attendance_qr.payload.token'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('data.staff.0.attendance_qr.svg_data_uri'));
    }

    public function test_qr_scan_check_in_and_check_out_store_location_and_scanner_user(): void
    {
        [$user, $token] = $this->createHellomUser();
        $organization = $this->createOrganizationContext($user);

        $staff = PosStaff::query()->create([
            'tenant_id' => $organization->pos_tenant_slug,
            'organization_id' => $organization->id,
            'name' => 'Salsa Kasir',
            'role' => 'cashier',
            'employment_status' => 'active',
            'permissions' => ['transactions' => true, 'reports' => false, 'products' => false, 'orders' => true, 'cash_control' => true],
            'attendance_qr_token' => 'static-qr-token-salsa',
            'attendance_qr_token_rotated_at' => now(),
        ]);

        $qrContent = json_encode([
            'type' => 'pos_staff_attendance',
            'version' => 1,
            'tenant' => $organization->pos_tenant_slug,
            'staff_id' => $staff->id,
            'staff_name' => $staff->name,
            'token' => $staff->attendance_qr_token,
        ]);

        $checkInResponse = $this->postJson('/api/v1/hellom/pos/staff/attendance/scan', [
            'qr_content' => $qrContent,
            'action' => 'check_in',
            'location_label' => 'Kasir depan',
            'latitude' => -6.175392,
            'longitude' => 106.827153,
        ], $this->hellomHeaders($token));

        $checkInResponse->assertOk()
            ->assertJsonPath('data.staff_name', 'Salsa Kasir')
            ->assertJsonPath('data.attendance.check_in_method', 'qr')
            ->assertJsonPath('data.attendance.check_in_location_label', 'Kasir depan');

        $this->assertDatabaseHas('pos_staff_attendances', [
            'staff_id' => $staff->id,
            'attendance_date' => now()->toDateString(),
            'check_in_method' => 'qr',
            'check_in_location_label' => 'Kasir depan',
            'check_in_scanned_by_user_id' => $user->id,
        ]);

        $checkOutResponse = $this->postJson('/api/v1/hellom/pos/staff/attendance/scan', [
            'qr_content' => $qrContent,
            'action' => 'check_out',
            'location_label' => 'Office belakang',
            'latitude' => -6.175000,
            'longitude' => 106.826800,
        ], $this->hellomHeaders($token));

        $checkOutResponse->assertOk()
            ->assertJsonPath('data.attendance.check_out_method', 'qr')
            ->assertJsonPath('data.attendance.check_out_location_label', 'Office belakang');

        $this->assertDatabaseHas('pos_staff_attendances', [
            'staff_id' => $staff->id,
            'attendance_date' => now()->toDateString(),
            'check_out_method' => 'qr',
            'check_out_location_label' => 'Office belakang',
            'check_out_scanned_by_user_id' => $user->id,
        ]);
    }

    public function test_regenerating_qr_invalidates_previous_token(): void
    {
        [$user, $token] = $this->createHellomUser();
        $organization = $this->createOrganizationContext($user);

        $staff = PosStaff::query()->create([
            'tenant_id' => $organization->pos_tenant_slug,
            'organization_id' => $organization->id,
            'name' => 'Dina Crew',
            'role' => 'cashier',
            'employment_status' => 'active',
            'permissions' => ['transactions' => true, 'reports' => false, 'products' => false, 'orders' => true, 'cash_control' => true],
            'attendance_qr_token' => 'old-token-dina',
            'attendance_qr_token_rotated_at' => now()->subDay(),
        ]);

        $regenerateResponse = $this->postJson("/api/v1/hellom/pos/staff/{$staff->id}/attendance-qr/regenerate", [], $this->hellomHeaders($token));
        $regenerateResponse->assertOk();

        $this->assertNotSame('old-token-dina', $staff->fresh()->attendance_qr_token);

        $invalidScanResponse = $this->postJson('/api/v1/hellom/pos/staff/attendance/scan', [
            'qr_content' => json_encode([
                'type' => 'pos_staff_attendance',
                'version' => 1,
                'tenant' => $organization->pos_tenant_slug,
                'staff_id' => $staff->id,
                'staff_name' => $staff->name,
                'token' => 'old-token-dina',
            ]),
            'action' => 'check_in',
        ], $this->hellomHeaders($token));

        $invalidScanResponse->assertStatus(404)
            ->assertJsonPath('error.code', 'STAFF_QR_NOT_FOUND');
    }

    private function createOrganizationContext($user): Organization
    {
        $organization = Organization::query()->create([
            'name' => 'POS Demo',
            'slug' => 'pos-demo',
            'pos_tenant_slug' => 'pos-demo-tenant',
            'status' => 'active',
        ]);

        $organization->users()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $organization;
    }
}
