<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kalkulator HPP (Tahap 1): free dashboard app "hpp". Data belongs to the organization (works
 * without POS); products can link to a POS product. Money = integer rupiah, per-unit costs keep
 * 4 decimals (e.g. Rp180/gram from Rp180.000/kg).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('apps')->where('slug', 'hpp')->exists()) {
            DB::table('apps')->insert(['slug' => 'hpp', 'name' => 'Kalkulator HPP', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('hpp_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->primary();
            $table->string('mode', 10)->default('quick'); // quick | recipe | pro
            $table->string('business_type', 20)->default('fnb'); // fnb | retail | service | production | digital
            $table->decimal('target_margin_pct', 5, 2)->default(40);
            $table->string('rounding', 10)->default('1000'); // none | 100 | 500 | 1000 | psych
            $table->string('price_method', 10)->default('last'); // last | average | fifo
            $table->unsignedInteger('labor_rate_per_hour')->default(0);
            $table->json('extra')->nullable();
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hpp_ingredients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name', 120);
            $table->string('kind', 12)->default('ingredient'); // ingredient | packaging
            $table->string('category', 40)->nullable();
            $table->string('use_unit', 12); // g | ml | pcs | porsi | …
            $table->string('buy_unit', 20); // kg | liter | pak | dus | …
            $table->decimal('buy_factor', 14, 4)->default(1); // use units in one buy unit
            $table->unsignedBigInteger('last_buy_price')->default(0); // per buy unit
            $table->decimal('cost_per_unit', 14, 4)->default(0); // per use unit, before yield
            $table->decimal('yield_pct', 5, 2)->default(100);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        Schema::create('hpp_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name', 160);
            $table->string('mode', 10)->default('recipe'); // quick | recipe
            $table->boolean('is_subrecipe')->default(false);
            $table->unsignedBigInteger('pos_product_id')->nullable()->index();
            $table->unsignedBigInteger('selling_price')->default(0);
            $table->decimal('target_margin_pct', 5, 2)->nullable();
            $table->decimal('yield_qty', 12, 3)->default(1); // units made by one recipe/batch
            $table->string('yield_unit', 12)->default('porsi');
            $table->unsignedBigInteger('quick_batch_cost')->default(0); // Mode Cepat: total spend of one batch
            $table->unsignedInteger('labor_minutes')->default(0); // per batch
            $table->unsignedBigInteger('other_cost')->default(0); // per batch (ongkir, …)
            $table->json('channel_prices')->nullable(); // {channel_id: price}
            // Cached by RecalculateHppCosts (lists and reports); detail pages compute live.
            $table->decimal('cost_material', 14, 2)->default(0);
            $table->decimal('cost_packaging', 14, 2)->default(0);
            $table->decimal('cost_full', 14, 2)->default(0);
            $table->timestamp('cost_updated_at')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        Schema::create('hpp_recipe_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('component_type', 12)->default('ingredient'); // ingredient | product (sub-recipe)
            $table->unsignedBigInteger('component_id');
            $table->decimal('qty', 14, 3);
            $table->string('note', 120)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['component_type', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hpp_recipe_items');
        Schema::dropIfExists('hpp_products');
        Schema::dropIfExists('hpp_ingredients');
        Schema::dropIfExists('hpp_settings');
        DB::table('apps')->where('slug', 'hpp')->delete();
    }
};
