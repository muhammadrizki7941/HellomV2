<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->foreignId('member_id')->constrained('pos_members');
            $table->foreignId('order_id')->nullable()->constrained('orders');
            $table->enum('type', ['earn', 'redeem', 'expire', 'bonus', 'manual']);
            $table->integer('points');         // + untuk earn, - untuk redeem
            $table->integer('balance_after'); // saldo setelah transaksi
            $table->string('description')->nullable();
            $table->json('metadata')->nullable(); // data tambahan
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_point_transactions');
    }
};