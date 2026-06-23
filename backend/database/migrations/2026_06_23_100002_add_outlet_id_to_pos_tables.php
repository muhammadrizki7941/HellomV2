<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add outlet_id to every POS-scoped table. Nullable for now so existing rows and
 * the backfill migration can populate it without breaking writes mid-deploy.
 */
return new class extends Migration
{
    /** Tables that hold per-outlet POS data. */
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
        foreach ($this->tables as $name) {
            if (!Schema::hasTable($name) || Schema::hasColumn($name, 'outlet_id')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('outlet_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('outlets')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            if (!Schema::hasTable($name) || !Schema::hasColumn($name, 'outlet_id')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('outlet_id');
            });
        }
    }
};
