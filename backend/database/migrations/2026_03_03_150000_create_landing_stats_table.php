<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('first_published_page_id')->nullable()->constrained('organization_landing_pages')->nullOnDelete();
            $table->timestamp('first_published_at')->nullable();
            $table->unsignedInteger('published_count')->default(0);
            $table->timestamps();

            $table->unique('organization_id');
            $table->index(['organization_id', 'published_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_stats');
    }
};
