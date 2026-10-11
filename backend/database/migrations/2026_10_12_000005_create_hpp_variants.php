<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kalkulator HPP (Tahap 3): variants & modifiers (size, extra shot, topping) with their own extra
 * ingredients (hpp_recipe_items.variant_id), linked to a POS option value for stock backflush;
 * backflush switch in settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hpp_product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('name', 80);
            $table->string('kind', 10)->default('modifier'); // size | modifier
            $table->integer('price_delta')->default(0);
            $table->unsignedBigInteger('pos_option_value_id')->nullable()->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::table('hpp_recipe_items', function (Blueprint $table) {
            $table->unsignedBigInteger('variant_id')->nullable()->after('product_id')->index();
        });
        Schema::table('hpp_settings', function (Blueprint $table) {
            $table->boolean('backflush_enabled')->default(true)->after('main_channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('hpp_settings', function (Blueprint $table) {
            $table->dropColumn('backflush_enabled');
        });
        Schema::table('hpp_recipe_items', function (Blueprint $table) {
            $table->dropIndex(['variant_id']);
            $table->dropColumn('variant_id');
        });
        Schema::dropIfExists('hpp_product_variants');
    }
};
