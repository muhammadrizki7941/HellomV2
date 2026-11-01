<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('unit', 32)->default('pcs');
            $table->unsignedInteger('cost_price')->nullable();
            $table->unsignedInteger('min_stock')->default(0);
            $table->boolean('has_variants')->default(false);
            $table->boolean('track_serial')->default(false);
            $table->unsignedSmallInteger('warranty_days')->nullable();
            $table->unique(['tenant_id', 'sku'], 'products_tenant_sku_unique');
            $table->index(['tenant_id', 'barcode'], 'products_tenant_barcode_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable();
            $table->timestamp('created_at_client')->nullable();
            $table->unique(['tenant_id', 'client_uuid'], 'orders_tenant_client_uuid_unique');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_id', 100)->index();
            $table->string('name', 150);
            $table->json('attributes')->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->unsignedInteger('price')->nullable();
            $table->unsignedInteger('stock')->nullable();
            $table->boolean('track_stock')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'sku'], 'product_variants_tenant_sku_unique');
            $table->index(['tenant_id', 'barcode'], 'product_variants_tenant_barcode_index');
        });

        Schema::create('product_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->string('tenant_id', 100)->index();
            $table->string('label', 80);
            $table->unsignedInteger('min_quantity')->default(1);
            $table->string('customer_type', 80)->nullable();
            $table->unsignedInteger('price');
            $table->timestamps();
            $table->index(['tenant_id', 'product_id', 'min_quantity'], 'price_tiers_product_quantity_index');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('movement_type', 24);
            $table->integer('qty');
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at_client')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'outlet_id', 'product_id', 'created_at'], 'stock_movement_product_index');
            $table->index(['reference_type', 'reference_id'], 'stock_movement_reference_index');
            $table->unique(['tenant_id', 'client_uuid'], 'stock_movements_tenant_client_uuid_unique');
        });

        Schema::create('product_serials', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('serial_number', 100);
            $table->string('status', 20)->default('available');
            $table->foreignId('sold_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'serial_number'], 'product_serials_tenant_serial_unique');
            $table->index(['tenant_id', 'outlet_id', 'status'], 'product_serials_availability_index');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('cost_price')->nullable();
            $table->foreignId('product_serial_id')->nullable()->constrained('product_serials')->nullOnDelete();
            $table->string('serial_number', 100)->nullable();
            $table->date('warranty_expires_at')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamp('created_at_client')->nullable();
        });

        Schema::create('pos_suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('name', 160);
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('pos_suppliers')->nullOnDelete();
            $table->string('reference_number', 100)->nullable();
            $table->unsignedInteger('total_cost')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('purchased_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('pos_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('pos_purchases')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('qty');
            $table->unsignedInteger('unit_cost');
            $table->timestamps();
        });

        Schema::create('pos_stocktakes', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_stocktake_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stocktake_id')->constrained('pos_stocktakes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('system_qty');
            $table->unsignedInteger('counted_qty');
            $table->integer('difference')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // Preserve the on-hand stock that was stored before the movement ledger existed.
        DB::table('products')
            ->whereNotNull('stock')
            ->orderBy('id')
            ->chunkById(500, function ($products): void {
                foreach ($products as $product) {
                    if ((int) $product->stock === 0) {
                        continue;
                    }

                    DB::table('stock_movements')->insert([
                        'tenant_id' => $product->tenant_id,
                        'outlet_id' => $product->outlet_id,
                        'product_id' => $product->id,
                        'movement_type' => 'opening_balance',
                        'qty' => (int) $product->stock,
                        'reference_type' => 'opening_balance',
                        'reference_id' => $product->id,
                        'note' => 'Saldo awal sebelum ledger stok',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropConstrainedForeignId('product_serial_id');
            $table->dropColumn(['cost_price', 'serial_number', 'warranty_expires_at', 'client_uuid', 'created_at_client']);
        });
        Schema::dropIfExists('pos_stocktake_items');
        Schema::dropIfExists('pos_stocktakes');
        Schema::dropIfExists('pos_purchase_items');
        Schema::dropIfExists('pos_purchases');
        Schema::dropIfExists('pos_suppliers');
        Schema::dropIfExists('product_serials');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_price_tiers');
        Schema::dropIfExists('product_variants');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_tenant_client_uuid_unique');
            $table->dropColumn(['client_uuid', 'created_at_client']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_tenant_sku_unique');
            $table->dropIndex('products_tenant_barcode_index');
            $table->dropColumn([
                'sku', 'barcode', 'unit', 'cost_price', 'min_stock', 'has_variants', 'track_serial', 'warranty_days',
            ]);
        });
    }
};
