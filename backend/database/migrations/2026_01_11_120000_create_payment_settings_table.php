<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();

            $table->boolean('cash_enabled')->default(true);
            $table->boolean('qris_static_enabled')->default(true);
            $table->boolean('qris_dynamic_enabled')->default(false);

            $table->string('default_method', 32)->default('cash');

            // Static QRIS (upload image or provide payload)
            $table->string('qris_static_image_path')->nullable();
            $table->text('qris_static_payload')->nullable();

            // Dynamic QRIS provider (secrets live in env)
            $table->string('dynamic_provider', 32)->default('midtrans');
            $table->boolean('dynamic_sandbox')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
