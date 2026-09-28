<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2B — order foundation (additive; no data is removed):
 * - orders: price breakdown (subtotal/tax/service/rounding/points discount), lifecycle
 *   timestamps, cancel/refund info, link to a table bill; outlet_id backfilled from tenant_id.
 * - order_number_sequences: atomic per-outlet daily counter (replaces "last + 1" race).
 * - table_bills: one open bill per table groups self-order + cashier orders.
 * - dining_tables: kind (table|counter), token_rotated_at; table code unique per outlet
 *   instead of globally; outlet_id backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('subtotal_amount')->default(0)->after('total_amount');
            $table->unsignedInteger('service_amount')->default(0)->after('subtotal_amount');
            $table->unsignedInteger('tax_amount')->default(0)->after('service_amount');
            $table->integer('rounding_amount')->default(0)->after('tax_amount');
            $table->unsignedInteger('points_discount_amount')->default(0)->after('discount_amount');
            $table->unsignedBigInteger('table_bill_id')->nullable()->after('dining_table_id')->index();
            $table->timestamp('confirmed_at')->nullable()->after('paid_at');
            $table->timestamp('cancelled_at')->nullable()->after('confirmed_at');
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_at');
            $table->timestamp('refunded_at')->nullable()->after('cancel_reason');
            $table->unsignedInteger('refund_amount')->default(0)->after('refunded_at');
            $table->index(['tenant_id', 'payment_status', 'paid_at'], 'orders_tenant_payment_paid_index');
        });

        // Old orders: subtotal = the amount they were charged before any discount.
        DB::statement('UPDATE orders SET subtotal_amount = total_amount WHERE subtotal_amount = 0');
        // outlet_id from the outlet whose tenant_slug the order carries.
        DB::statement('UPDATE orders o JOIN outlets ot ON ot.tenant_slug = o.tenant_id SET o.outlet_id = ot.id WHERE o.outlet_id IS NULL');

        Schema::create('order_number_sequences', function (Blueprint $table) {
            $table->string('tenant_id', 100);
            $table->date('sequence_date');
            $table->unsignedInteger('last_number')->default(0);
            $table->primary(['tenant_id', 'sequence_date']);
        });

        Schema::create('table_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('tenant_id', 100)->index();
            $table->foreignId('dining_table_id')->constrained('dining_tables')->cascadeOnDelete();
            $table->string('status', 16)->default('open'); // open | paid | cancelled
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['dining_table_id', 'status']);
        });

        Schema::table('dining_tables', function (Blueprint $table) {
            $table->string('kind', 16)->default('table')->after('name'); // table | counter
            $table->timestamp('token_rotated_at')->nullable()->after('is_active');
        });

        DB::statement('UPDATE dining_tables d JOIN outlets ot ON ot.tenant_slug = d.tenant_id SET d.outlet_id = ot.id WHERE d.outlet_id IS NULL');

        // Table codes only need to be unique inside one outlet.
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->dropUnique('dining_tables_code_unique');
            $table->unique(['tenant_id', 'code'], 'dining_tables_tenant_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->dropUnique('dining_tables_tenant_code_unique');
            $table->unique('code', 'dining_tables_code_unique');
            $table->dropColumn(['kind', 'token_rotated_at']);
        });
        Schema::dropIfExists('table_bills');
        Schema::dropIfExists('order_number_sequences');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_tenant_payment_paid_index');
            $table->dropIndex(['table_bill_id']);
            $table->dropColumn([
                'subtotal_amount', 'service_amount', 'tax_amount', 'rounding_amount', 'points_discount_amount',
                'table_bill_id', 'confirmed_at', 'cancelled_at', 'cancel_reason', 'refunded_at', 'refund_amount',
            ]);
        });
    }
};
