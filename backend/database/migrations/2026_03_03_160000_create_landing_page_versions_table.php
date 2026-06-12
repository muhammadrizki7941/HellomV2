<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landing_page_id')->constrained('organization_landing_pages')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('source_status', 20)->default('draft');
            $table->string('title');
            $table->string('slug');
            $table->json('content')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['landing_page_id', 'version_no'], 'landing_page_versions_page_version_unique');
            $table->index(['organization_id', 'landing_page_id'], 'landing_page_versions_org_page_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_versions');
    }
};
