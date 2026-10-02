<?php

namespace Tests\Admin;

use App\Models\AuditLog;
use Illuminate\Support\Str;

/** P2-4: sensitive admin changes are audited, without secret values. */
class AdminAuditTrailTest extends AdminTestCase
{
    public function test_gateway_and_manual_payment_changes_are_audited_without_secrets(): void
    {
        $admin = $this->superAdmin();
        $secret = 'super-secret-' . Str::random(12);

        $this->api($admin, 'PUT', '/admin/billing/provider-config', ['provider' => 'doku', 'client_id' => 'BRN-123', 'secret_key' => $secret, 'is_production' => false])->assertOk();
        $log = AuditLog::query()->where('action', 'admin.gateway_config.updated')->latest('id')->firstOrFail();
        $this->assertSame((int) $admin->id, (int) $log->user_id);
        $this->assertContains('secret_key', $log->new_values['fields']);
        $this->assertStringNotContainsString($secret, json_encode($log->getAttributes()));

        $this->api($admin, 'POST', '/admin/billing/manual-payment-config', ['enabled' => true, 'methods' => ['bank_transfer' => ['enabled' => true, 'account_number' => '1234567890']]])->assertOk();
        $manual = AuditLog::query()->where('action', 'admin.manual_payment_config.updated')->latest('id')->firstOrFail();
        $this->assertSame('1234567890', data_get($manual->new_values, 'methods.bank_transfer.account_number'));
    }

    public function test_promo_audit_points_at_the_campaign(): void
    {
        $admin = $this->superAdmin();
        $id = $this->api($admin, 'POST', '/admin/promos', ['code' => 'AUD' . Str::upper(Str::random(5)), 'name' => 'Audit', 'type' => 'percentage', 'value' => 10, 'is_active' => true])
            ->assertCreated()->json('data.id');

        $log = AuditLog::query()->where('action', 'promo_campaign.created')->latest('id')->firstOrFail();
        $this->assertSame('promo_campaign', $log->entity_type);
        $this->assertSame((int) $id, (int) $log->entity_id);

        $this->api($admin, 'PUT', "/admin/promos/{$id}", ['value' => 150])->assertStatus(422)->assertJsonPath('error.code', 'PROMO_VALUE_TOO_HIGH');
    }
}
