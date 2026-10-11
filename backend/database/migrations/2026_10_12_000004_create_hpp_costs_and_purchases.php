<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kalkulator HPP (Tahap 2): overhead + allocation, sales channels (fees), purchases that update
 * ingredient prices and stock, stock movements, daily cost snapshots. outlet_id 0 = no outlet
 * (shops without POS) — 0 instead of NULL so it can sit in unique keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hpp_settings', function (Blueprint $table) {
            $table->string('overhead_method', 12)->default('per_unit')->after('labor_rate_per_hour'); // per_unit | revenue_pct | labor_hour
            $table->unsignedInteger('monthly_units')->default(0)->after('overhead_method');
            $table->unsignedBigInteger('monthly_revenue')->default(0)->after('monthly_units');
            $table->unsignedInteger('monthly_labor_hours')->default(0)->after('monthly_revenue');
            $table->unsignedBigInteger('main_channel_id')->nullable()->after('monthly_labor_hours');
        });

        Schema::create('hpp_overheads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('outlet_id')->default(0);
            $table->string('name', 120);
            $table->string('category', 20)->default('other'); // rent | utility | salary | subscription | depreciation | other
            $table->unsignedBigInteger('amount'); // per month
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hpp_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name', 80);
            $table->string('kind', 20)->default('custom'); // cash | qris | hellom | gofood | grabfood | shopeefood | custom
            $table->decimal('fee_pct', 5, 2)->default(0);
            $table->unsignedInteger('fee_flat')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('hpp_purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('outlet_id')->default(0);
            $table->date('purchased_at');
            $table->string('supplier', 120)->nullable();
            $table->unsignedBigInteger('total')->default(0);
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'purchased_at']);
        });

        Schema::create('hpp_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id')->index();
            $table->unsignedBigInteger('ingredient_id')->index();
            $table->decimal('qty', 14, 3); // in buy units
            $table->unsignedBigInteger('total_price');
            $table->decimal('qty_use', 16, 3); // in use units
            $table->decimal('unit_cost', 14, 4); // per use unit
            $table->decimal('remaining_qty', 16, 3)->default(0); // FIFO lot (Pro)
            $table->decimal('previous_cost', 14, 4)->default(0); // cost per use unit before this purchase
            $table->timestamps();
        });

        Schema::create('hpp_ingredient_stocks', function (Blueprint $table) {
            $table->unsignedBigInteger('ingredient_id');
            $table->unsignedBigInteger('outlet_id')->default(0);
            $table->decimal('qty', 16, 3)->default(0);
            $table->timestamps();
            $table->primary(['ingredient_id', 'outlet_id']);
        });

        Schema::create('hpp_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->unsignedBigInteger('outlet_id')->default(0);
            $table->string('type', 12); // purchase | sale | adjust | opname | waste
            $table->decimal('qty', 16, 3); // + in, − out (use units)
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->string('ref_type', 20)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('note', 160)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['organization_id', 'ingredient_id', 'created_at'], 'hsm_org_ing_time_idx');
            $table->index(['ref_type', 'ref_id'], 'hsm_ref_idx');
        });

        Schema::create('hpp_cost_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->date('snapshot_date');
            $table->decimal('cost_material', 14, 2)->default(0);
            $table->decimal('cost_packaging', 14, 2)->default(0);
            $table->decimal('cost_full', 14, 2)->default(0);
            $table->unsignedBigInteger('selling_price')->default(0);
            $table->decimal('margin_pct', 6, 1)->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hpp_cost_snapshots');
        Schema::dropIfExists('hpp_stock_movements');
        Schema::dropIfExists('hpp_ingredient_stocks');
        Schema::dropIfExists('hpp_purchase_items');
        Schema::dropIfExists('hpp_purchases');
        Schema::dropIfExists('hpp_channels');
        Schema::dropIfExists('hpp_overheads');
        Schema::table('hpp_settings', function (Blueprint $table) {
            $table->dropColumn(['overhead_method', 'monthly_units', 'monthly_revenue', 'monthly_labor_hours', 'main_channel_id']);
        });
    }
};
