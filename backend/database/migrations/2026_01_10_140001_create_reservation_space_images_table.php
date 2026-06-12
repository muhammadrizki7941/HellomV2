<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_space_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_space_id')->constrained('reservation_spaces')->cascadeOnDelete();
            $table->string('image_path');
            $table->string('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['reservation_space_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_space_images');
    }
};
