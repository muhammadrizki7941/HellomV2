<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hellom Page (Fase 5): indexes for queries that run on every payment or every few minutes.
 * - landing_page_orders.gateway_trx_id: duplicate-payment check in LandingPaymentService::settle.
 * - landing_page_orders (status, created_at): landing:orders reconcile (every 5 minutes).
 * - landing_page_orders (organization_id, paid_at): sales per product/source in the stats report.
 * - seller_balance_ledger (order_id, type): "already released?" lookup in landing:orders release.
 * Additive only; skipped when the index already exists.
 */
return new class extends Migration
{
    private const INDEXES = [
        'landing_page_orders' => [
            'lpo_gateway_trx_idx' => ['gateway_trx_id'],
            'lpo_status_created_idx' => ['status', 'created_at'],
            'lpo_org_paid_idx' => ['organization_id', 'paid_at'],
        ],
        'seller_balance_ledger' => [
            'sbl_order_type_idx' => ['order_id', 'type'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::hasTable($table) && !Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        // MySQL drops the implicit foreign-key index on order_id once (order_id, type) covers
        // it; the foreign key needs an index back before the composite one can go.
        if (Schema::hasIndex('seller_balance_ledger', 'sbl_order_type_idx') && !Schema::hasIndex('seller_balance_ledger', ['order_id'])) {
            Schema::table('seller_balance_ledger', fn (Blueprint $t) => $t->index('order_id', 'seller_balance_ledger_order_id_foreign'));
        }
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
