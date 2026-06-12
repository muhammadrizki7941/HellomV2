<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landing_page_id')->constrained('organization_landing_pages')->cascadeOnDelete();
            $table->string('domain', 190);
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'domain'], 'landing_domains_org_domain_unique');
            $table->index(['landing_page_id', 'is_primary'], 'landing_domains_page_primary_idx');
            $table->index(['organization_id', 'status'], 'landing_domains_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_domains');
    }
};
