<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_product_id')->constrained('products')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('item_product_id')->constrained('products')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['package_product_id', 'item_product_id']);
            $table->index(['package_product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_items');
    }
};
