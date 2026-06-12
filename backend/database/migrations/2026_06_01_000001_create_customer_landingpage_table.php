<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_landingpage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landing_page_id')->constrained('organization_landing_pages')->cascadeOnDelete();
            $table->string('block_id', 80)->nullable();
            $table->string('form_title', 160)->nullable();
            $table->string('name', 160)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('email', 190)->nullable();
            $table->json('fields')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('ip_address', 80)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at'], 'customer_landingpage_org_created_idx');
            $table->index(['landing_page_id', 'created_at'], 'customer_landingpage_page_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_landingpage');
    }
};
