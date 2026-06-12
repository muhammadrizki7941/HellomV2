<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_promotions', function (Blueprint $table) {
            $table->string('promo_code', 80)->nullable()->after('title');
            $table->unsignedInteger('bonus_points')->default(0)->after('link_url');
            $table->unsignedInteger('minimum_spend')->default(0)->after('bonus_points');
            $table->unsignedInteger('claim_limit')->nullable()->after('minimum_spend');
            $table->unsignedInteger('claimed_count')->default(0)->after('claim_limit');
            $table->boolean('requires_reservation')->default(false)->after('claimed_count');
            $table->text('terms')->nullable()->after('description');
        });

        Schema::table('reservation_spaces', function (Blueprint $table) {
            $table->string('tenant_id', 100)->nullable()->after('id');
            $table->index(['tenant_id', 'is_active'], 'reservation_spaces_tenant_active_idx');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->string('tenant_id', 100)->nullable()->after('id');
            $table->index(['tenant_id', 'status', 'scheduled_at'], 'reservations_tenant_status_scheduled_idx');
        });

        Schema::table('reservation_space_items', function (Blueprint $table) {
            $table->boolean('is_required')->default(false)->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_space_items', function (Blueprint $table) {
            $table->dropColumn('is_required');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_tenant_status_scheduled_idx');
            $table->dropColumn('tenant_id');
        });

        Schema::table('reservation_spaces', function (Blueprint $table) {
            $table->dropIndex('reservation_spaces_tenant_active_idx');
            $table->dropColumn('tenant_id');
        });

        Schema::table('site_promotions', function (Blueprint $table) {
            $table->dropColumn([
                'promo_code',
                'bonus_points',
                'minimum_spend',
                'claim_limit',
                'claimed_count',
                'requires_reservation',
                'terms',
            ]);
        });
    }
};
