<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_product_docs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('digital_products')->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('doc_type', 20);
            $table->longText('content')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('video_url', 500)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_product_docs');
    }
};
