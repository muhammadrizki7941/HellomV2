<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_space_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_space_id')->constrained('reservation_spaces')->cascadeOnDelete();

            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name');
            $table->unsignedInteger('unit_price')->default(0);
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['reservation_space_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_space_items');
    }
};
