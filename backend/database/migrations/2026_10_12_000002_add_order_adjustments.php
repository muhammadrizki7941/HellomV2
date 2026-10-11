<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS order adjustments (App\Services\Pos\OrderAdjustmentService), additive only:
 * - orders.manual_discount_amount: cashier discount approved with the owner PIN (rule in
 *   payment_meta.manual_discount); orders.split_from_order_id: order created by "pisah bill".
 * - table_bills.merged_into_bill_id: bill moved/merged into another table's bill (status "merged").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('manual_discount_amount')->default(0)->after('points_discount_amount');
            $table->unsignedBigInteger('split_from_order_id')->nullable()->after('table_bill_id')->index();
        });
        Schema::table('table_bills', function (Blueprint $table) {
            $table->unsignedBigInteger('merged_into_bill_id')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('table_bills', function (Blueprint $table) {
            $table->dropColumn('merged_into_bill_id');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['split_from_order_id']);
            $table->dropColumn(['manual_discount_amount', 'split_from_order_id']);
        });
    }
};
