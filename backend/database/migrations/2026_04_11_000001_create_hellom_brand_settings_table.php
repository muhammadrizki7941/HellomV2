<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hellom_brand_settings', function (Blueprint $table) {
            $table->id();
            $table->string('logo_path')->nullable();
            $table->string('logo_dark_path')->nullable();
            $table->string('business_name')->default('Hellom');
            $table->string('tagline')->nullable();
            $table->string('primary_color')->default('#0c0c0c');
            $table->string('accent_color')->default('#c8ff47');
            $table->string('background_color')->default('#0c0c0c');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hellom_brand_settings');
    }
};