<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_settings', function (Blueprint $table) {
            $table->string('earn_method', 40)->default('per_1000')->after('enabled');

            // Custom unit earning: points = floor(total / points_unit_amount) * points_per_unit
            $table->unsignedInteger('points_unit_amount')->default(1000)->after('points_per_1000');
            $table->unsignedInteger('points_per_unit')->default(1)->after('points_unit_amount');

            // Multiples of minimum spend: points = floor(total / min_spend_amount) * points_per_min_spend
            $table->unsignedInteger('points_per_min_spend')->default(0)->after('min_spend_amount');

            // Flat points per order when total >= min_spend_amount
            $table->unsignedInteger('flat_points_per_order')->default(0)->after('points_per_min_spend');

            // Redeem points to discount
            $table->boolean('redeem_enabled')->default(false)->after('max_points_per_order');
            $table->unsignedInteger('redeem_rp_per_point')->default(0)->after('redeem_enabled');
            $table->unsignedInteger('redeem_min_spend_amount')->default(0)->after('redeem_rp_per_point');
            $table->unsignedInteger('redeem_max_points_per_order')->nullable()->after('redeem_min_spend_amount');
            $table->unsignedInteger('redeem_max_discount_rp')->nullable()->after('redeem_max_points_per_order');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('discount_amount')->default(0)->after('total_amount');
            $table->unsignedInteger('redeemed_points')->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'redeemed_points']);
        });

        Schema::table('loyalty_settings', function (Blueprint $table) {
            $table->dropColumn([
                'earn_method',
                'points_unit_amount',
                'points_per_unit',
                'points_per_min_spend',
                'flat_points_per_order',
                'redeem_enabled',
                'redeem_rp_per_point',
                'redeem_min_spend_amount',
                'redeem_max_points_per_order',
                'redeem_max_discount_rp',
            ]);
        });
    }
};
