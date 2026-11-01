<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->foreignId('source_outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('destination_outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('reference_number', 100)->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'client_uuid'], 'stock_transfers_tenant_client_uuid_unique');
        });

        Schema::create('pos_stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('pos_stock_transfers')->cascadeOnDelete();
            $table->foreignId('source_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('destination_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('source_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('destination_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_stock_transfer_items');
        Schema::dropIfExists('pos_stock_transfers');
    }
};
