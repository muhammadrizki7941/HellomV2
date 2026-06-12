<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_about_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('landing_about_settings', 'products_label')) {
                $table->string('products_label')->default('Products')->after('support_label');
            }
            if (!Schema::hasColumn('landing_about_settings', 'products_heading')) {
                $table->string('products_heading')->default('Produk digital premium untuk hasil maksimal.')->after('products_label');
            }
            if (!Schema::hasColumn('landing_about_settings', 'products_description')) {
                $table->text('products_description')->nullable()->after('products_heading');
            }
            if (!Schema::hasColumn('landing_about_settings', 'products_cta_label')) {
                $table->string('products_cta_label')->default('Lihat semua produk')->after('products_description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('landing_about_settings', function (Blueprint $table) {
            $dropColumns = [];

            foreach (['products_cta_label', 'products_description', 'products_heading', 'products_label'] as $column) {
                if (Schema::hasColumn('landing_about_settings', $column)) {
                    $dropColumns[] = $column;
                }
            }

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
