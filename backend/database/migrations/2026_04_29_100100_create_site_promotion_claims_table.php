<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_promotion_claims', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->foreignId('site_promotion_id')->constrained('site_promotions')->cascadeOnDelete();
            $table->foreignId('pos_member_id')->nullable()->constrained('pos_members')->nullOnDelete();
            $table->string('customer_name', 120);
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_email', 160)->nullable();
            $table->string('claim_code', 80)->nullable();
            $table->unsignedInteger('bonus_points_awarded')->default(0);
            $table->string('claimed_via', 40)->default('customer_order');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['site_promotion_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_promotion_claims');
    }
};
