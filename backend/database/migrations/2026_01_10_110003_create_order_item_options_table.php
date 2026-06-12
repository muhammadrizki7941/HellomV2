<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();

            // Snapshot fields (do not rely on current product option definitions)
            $table->unsignedBigInteger('product_option_id')->nullable();
            $table->unsignedBigInteger('product_option_value_id')->nullable();
            $table->string('option_name');
            $table->string('value_name');
            $table->unsignedInteger('price_delta')->default(0);
            $table->timestamps();

            $table->index(['order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_options');
    }
};
