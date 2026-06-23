<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safe data migration: every organization gets one primary "Outlet Utama",
 * then existing POS rows are assigned to it (matched by legacy tenant_id slug,
 * falling back to organization_id). No data is deleted.
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

        // 1. Ensure a primary outlet per organization.
        $organizations = DB::table('organizations')->get();
        foreach ($organizations as $org) {
            $alreadyHasOutlet = DB::table('outlets')->where('organization_id', $org->id)->exists();
            if ($alreadyHasOutlet) {
                continue;
            }

            $tenantSlug = $org->pos_tenant_slug ?: $org->slug;
            $name = $org->pos_tenant_name ?: trim(($org->name ?? 'Outlet') . ' - Outlet Utama');

            DB::table('outlets')->insert([
                'organization_id' => $org->id,
                'name' => $name,
                'slug' => 'utama',
                'tenant_slug' => $tenantSlug,
                'phone' => $org->phone ?? null,
                'email' => $org->email ?? null,
                'address' => $org->address ?? null,
                'is_primary' => true,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Assign outlet_id to existing rows.
        foreach ($this->tables as $name) {
            if (!Schema::hasTable($name) || !Schema::hasColumn($name, 'outlet_id')) {
                continue;
            }

            // tenant_id is a string slug on some tables and a numeric organization id on
            // others (legacy). Detect the column type and match accordingly.
            if (Schema::hasColumn($name, 'tenant_id')) {
                if ($this->isNumericColumn($name, 'tenant_id')) {
                    // tenant_id holds the organization id.
                    DB::statement("UPDATE `{$name}` t JOIN `outlets` o ON o.organization_id = t.tenant_id AND o.is_primary = 1 SET t.outlet_id = o.id WHERE t.outlet_id IS NULL");
                } else {
                    // tenant_id holds the pos tenant slug.
                    DB::statement("UPDATE `{$name}` t JOIN `outlets` o ON CAST(o.tenant_slug AS CHAR) = CAST(t.tenant_id AS CHAR) SET t.outlet_id = o.id WHERE t.outlet_id IS NULL");
                    // Some legacy rows store the organization id as a string in tenant_id.
                    DB::statement("UPDATE `{$name}` t JOIN `outlets` o ON o.organization_id = CAST(t.tenant_id AS UNSIGNED) AND o.is_primary = 1 SET t.outlet_id = o.id WHERE t.outlet_id IS NULL AND t.tenant_id REGEXP '^[0-9]+$'");
                }
            }

            // Fallback: rows that still carry an organization_id but no resolved outlet.
            if (Schema::hasColumn($name, 'organization_id')) {
                DB::statement("UPDATE `{$name}` t JOIN `outlets` o ON o.organization_id = t.organization_id AND o.is_primary = 1 SET t.outlet_id = o.id WHERE t.outlet_id IS NULL");
            }
        }
    }

    private function isNumericColumn(string $table, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE as data_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        $type = strtolower($row->data_type ?? '');

        return in_array($type, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'decimal', 'double', 'float'], true);
    }

    public function down(): void
    {
        // Clear assignments; outlets table itself is dropped by its own migration's down().
        foreach ($this->tables as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'outlet_id')) {
                DB::table($name)->update(['outlet_id' => null]);
            }
        }

        if (Schema::hasTable('outlets')) {
            DB::table('outlets')->where('slug', 'utama')->where('is_primary', true)->delete();
        }
    }
};
