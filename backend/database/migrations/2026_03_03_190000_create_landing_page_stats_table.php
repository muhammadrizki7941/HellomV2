<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('landing_page_stats')) {
            return;
        }

        Schema::create('landing_page_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landing_page_id')->constrained('organization_landing_pages')->cascadeOnDelete();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();

            $table->unique('landing_page_id');
            $table->index(['organization_id', 'views_count'], 'landing_page_stats_org_views_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('landing_page_stats')) {
            return;
        }

        Schema::dropIfExists('landing_page_stats');
    }
};
