<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('app_slug', 80)->default('landing_builder');
            $table->string('disk', 40)->default('public');
            $table->string('path', 255);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('original_name', 255)->nullable();
            $table->boolean('is_public')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'app_slug', 'created_at'], 'file_assets_org_app_created_idx');
            $table->index(['organization_id', 'app_slug', 'is_public'], 'file_assets_org_app_public_idx');
            $table->unique(['organization_id', 'path'], 'file_assets_org_path_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_assets');
    }
};
