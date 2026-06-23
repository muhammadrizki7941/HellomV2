<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up backfill: some legacy POS tables store the organization id as a
 * string inside tenant_id (e.g. '18'). Resolve those to the primary outlet.
 * Idempotent — only touches rows still missing an outlet_id.
 */
return new class extends Migration
{
    private array $tables = [
        'products',
        'categories',
        'dining_tables',
        'site_promotions',
        'orders',
        'pos_staff',
        'pos_staff_shifts',
        'pos_staff_attendances',
        'pos_staff_cash_logs',
        'pos_members',
        'pos_point_transactions',
        'pos_reward_rules',
        'pos_redemptions',
        'pos_loyalty_settings',
        'pos_payment_settings',
        'reservation_spaces',
        'reservations',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('outlets')) {
            return;
        }

        foreach ($this->tables as $name) {
            if (!Schema::hasTable($name) || !Schema::hasColumn($name, 'outlet_id') || !Schema::hasColumn($name, 'tenant_id')) {
                continue;
            }

            // tenant_id stored as a numeric organization id (string or int).
            DB::statement("UPDATE `{$name}` t JOIN `outlets` o ON o.organization_id = CAST(t.tenant_id AS UNSIGNED) AND o.is_primary = 1 SET t.outlet_id = o.id WHERE t.outlet_id IS NULL AND CAST(t.tenant_id AS CHAR) REGEXP '^[0-9]+$'");
        }
    }

    public function down(): void
    {
        // No-op: assignments are reverted by the primary backfill migration's down().
    }
};
