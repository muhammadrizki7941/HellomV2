<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_products', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 255)->unique();
            $table->string('name', 255);
            $table->string('tagline', 255)->nullable();
            $table->longText('description')->nullable();
            $table->string('category', 50);
            $table->string('type', 50);
            $table->unsignedBigInteger('price')->default(0);
            $table->string('currency', 10)->default('IDR');
            $table->string('thumbnail_url', 500)->nullable();
            $table->json('preview_images')->nullable();
            $table->json('tech_stack')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->integer('total_purchases')->default(0);
            $table->integer('total_downloads')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'is_featured']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_products');
    }
};
