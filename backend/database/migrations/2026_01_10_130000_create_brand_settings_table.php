<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_settings', function (Blueprint $table) {
            $table->id();

            $table->string('business_name', 120)->default('Self Order');
            $table->string('tagline', 160)->nullable();
            $table->text('about')->nullable();

            $table->string('phone', 40)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('instagram', 120)->nullable();
            $table->string('website', 120)->nullable();

            $table->string('logo_light_path', 255)->nullable();
            $table->string('logo_dark_path', 255)->nullable();
            $table->string('favicon_path', 255)->nullable();

            $table->string('primary_color', 20)->default('#0f172a');
            $table->string('secondary_color', 20)->default('#334155');
            $table->string('accent_color', 20)->default('#10b981');
            $table->string('background_color', 20)->default('#f8fafc');

            $table->unsignedSmallInteger('button_radius')->default(18);
            $table->string('font_family', 120)->default('system-ui');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_settings');
    }
};
