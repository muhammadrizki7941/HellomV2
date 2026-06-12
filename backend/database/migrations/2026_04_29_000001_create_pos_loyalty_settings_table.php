<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->unique();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('points_per_amount')->default(1000);
            $table->unsignedInteger('min_spend_amount')->default(0);
            $table->unsignedInteger('max_points_per_order')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_loyalty_settings');
    }
};
