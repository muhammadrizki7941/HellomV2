<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landing_page_id')->constrained('organization_landing_pages')->cascadeOnDelete();
            $table->string('block_key', 120);
            $table->string('block_type', 80);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('content')->nullable();
            $table->timestamps();

            $table->index(['landing_page_id', 'sort_order'], 'landing_blocks_page_order_idx');
            $table->unique(['landing_page_id', 'block_key'], 'landing_blocks_page_key_unique');
            $table->index(['organization_id', 'created_at'], 'landing_blocks_org_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_blocks');
    }
};
