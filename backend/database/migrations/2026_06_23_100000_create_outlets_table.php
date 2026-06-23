<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-outlet foundation. An organization can own several outlets (branches),
 * each with its own catalog, orders, reports, staff, and self-order page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outlets')) {
            return;
        }

        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 120);
            // Legacy POS scoping key (mirrors organizations.pos_tenant_slug) so existing
            // tenant_id-based data keeps resolving while we migrate to outlet_id.
            $table->string('tenant_slug', 120)->nullable()->index();
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->text('address')->nullable();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('banner_path')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlets');
    }
};
