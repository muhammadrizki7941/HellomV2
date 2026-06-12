<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('base_unit_price')->nullable()->after('unit_price');
            $table->unsignedInteger('options_total')->default(0)->after('base_unit_price');
        });

        // Backfill existing rows
        DB::table('order_items')->whereNull('base_unit_price')->update([
            'base_unit_price' => DB::raw('unit_price'),
            'options_total' => DB::raw('0'),
        ]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['base_unit_price', 'options_total']);
        });
    }
};
