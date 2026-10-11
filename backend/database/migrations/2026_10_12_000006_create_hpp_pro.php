<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kalkulator HPP Pro (Tahap 4, berbayar): free with an active POS subscription, or its own plan
 * (hpp_pro_monthly / hpp_pro_yearly — prices editable by the super admin in Admin › Paket).
 * Tables: ingredient price per outlet, stock opname (counts) for theoretical vs actual usage.
 */
return new class extends Migration
{
    private const FEATURES = ['Selisih stok teoritis vs aktual (stok opname)', 'Simulasi "bagaimana jika"', 'Metode harga FIFO', 'Harga bahan per outlet', 'Ekspor laporan'];

    public function up(): void
    {
        $plans = [
            ['slug' => 'hpp_pro_monthly', 'name' => 'Kalkulator HPP Pro - Bulanan', 'type' => 'subscription', 'price' => 29000, 'billing_cycles' => ['monthly'], 'duration_days' => 30, 'sort_order' => 200],
            ['slug' => 'hpp_pro_yearly', 'name' => 'Kalkulator HPP Pro - Tahunan', 'type' => 'one_time', 'price' => 290000, 'billing_cycles' => ['yearly'], 'duration_days' => 365, 'sort_order' => 201],
        ];
        foreach ($plans as $plan) {
            if (DB::table('plans')->where('slug', $plan['slug'])->exists()) {
                continue;
            }
            DB::table('plans')->insert([
                'slug' => $plan['slug'], 'name' => $plan['name'], 'type' => $plan['type'], 'price' => $plan['price'], 'is_active' => true,
                'description' => 'Fitur Pro Kalkulator HPP. Gratis untuk pelanggan POS Kasir.',
                'features' => json_encode(self::FEATURES), 'billing_cycles' => json_encode($plan['billing_cycles']), 'duration_days' => $plan['duration_days'],
                'is_visible' => true, 'is_recommended' => false, 'sort_order' => $plan['sort_order'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::table('hpp_settings', function (Blueprint $table) {
            $table->boolean('per_outlet_prices')->default(false)->after('backflush_enabled');
        });

        Schema::create('hpp_ingredient_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('ingredient_id');
            $table->unsignedBigInteger('outlet_id');
            $table->decimal('cost_per_unit', 14, 4);
            $table->timestamps();
            $table->primary(['ingredient_id', 'outlet_id']);
        });

        Schema::create('hpp_stock_counts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('outlet_id')->default(0);
            $table->dateTime('counted_at');
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'outlet_id', 'counted_at'], 'hsc_org_outlet_time_idx');
        });

        Schema::create('hpp_stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('count_id')->index();
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('system_qty', 16, 3);
            $table->decimal('counted_qty', 16, 3);
            $table->decimal('diff', 16, 3);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hpp_stock_count_items');
        Schema::dropIfExists('hpp_stock_counts');
        Schema::dropIfExists('hpp_ingredient_prices');
        Schema::table('hpp_settings', function (Blueprint $table) {
            $table->dropColumn('per_outlet_prices');
        });
        // Plans stay: subscriptions may reference them.
    }
};
