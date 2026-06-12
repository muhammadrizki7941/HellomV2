<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_reward_rules', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->string('name');
            $table->enum('trigger_type', [
                'points_threshold',    // kumpul X poin
                'orders_threshold',    // X kali beli
                'spend_threshold',     // total belanja > Rp X
            ]);
            $table->integer('trigger_value');
            $table->enum('reward_type', [
                'free_product',        // produk gratis
                'discount_percent',    // diskon %
                'discount_fixed',      // diskon nominal
                'bonus_points',        // poin tambahan
            ]);
            $table->integer('reward_value');
            $table->foreignId('reward_product_id')
                ->nullable()
                ->constrained('products');
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_reward_rules');
    }
};