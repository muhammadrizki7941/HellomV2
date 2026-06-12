<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'member_id')) {
                $table->foreignId('member_id')
                    ->nullable()
                    ->constrained('pos_members')
                    ->after('tenant_id');
            }
            if (!Schema::hasColumn('orders', 'points_earned')) {
                $table->integer('points_earned')->default(0)->after('total_amount');
            }
            if (!Schema::hasColumn('orders', 'points_redeemed')) {
                $table->integer('points_redeemed')->default(0)->after('points_earned');
            }
            if (!Schema::hasColumn('orders', 'discount_amount')) {
                $table->integer('discount_amount')->default(0)->after('points_redeemed');
            }
            if (!Schema::hasColumn('orders', 'final_amount')) {
                $table->integer('final_amount')->default(0)->after('discount_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
            $table->dropColumn([
                'member_id',
                'points_earned',
                'points_redeemed',
                'discount_amount',
                'final_amount'
            ]);
        });
    }
};