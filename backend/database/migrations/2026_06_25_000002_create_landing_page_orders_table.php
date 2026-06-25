<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('landing_page_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete(); // seller
            $table->unsignedBigInteger('landing_page_id')->nullable();
            $table->string('block_id', 64)->nullable();
            $table->string('product_kind', 20)->default('product'); // product|pdf
            $table->string('product_name', 200);
            $table->bigInteger('amount');             // gross paid by buyer
            $table->bigInteger('commission_amount')->default(0); // platform cut
            $table->bigInteger('net_amount');         // credited to seller wallet (pending)
            $table->string('buyer_name', 150)->nullable();
            $table->string('buyer_email', 150)->nullable();
            $table->string('buyer_phone', 40)->nullable();
            $table->string('status', 20)->default('pending'); // pending|paid|failed
            $table->string('provider', 30)->nullable();
            $table->string('reference_id', 80)->unique();
            $table->string('gateway_ref', 120)->nullable();
            $table->string('download_token', 64)->nullable()->unique();
            $table->string('file_url', 2048)->nullable(); // digital file / PDF to deliver on success
            $table->timestamp('settlement_eta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'lpo_org_status_idx');
            $table->index(['landing_page_id'], 'lpo_page_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_orders');
    }
};
