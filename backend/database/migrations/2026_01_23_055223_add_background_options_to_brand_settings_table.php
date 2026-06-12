<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('brand_settings', function (Blueprint $table) {
            $table->string('background_gradient', 500)->nullable()->after('background_color');
            $table->string('background_pattern', 50)->default('mesh')->after('background_gradient');
            $table->string('background_image_path', 255)->nullable()->after('background_pattern');
            $table->decimal('background_overlay_opacity', 3, 2)->default(0.00)->after('background_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('brand_settings', function (Blueprint $table) {
            $table->dropColumn(['background_gradient', 'background_pattern', 'background_image_path', 'background_overlay_opacity']);
        });
    }
};
