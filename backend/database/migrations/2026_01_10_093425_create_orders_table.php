<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique();

            $table->foreignId('dining_table_id')->nullable()->constrained('dining_tables')->cascadeOnUpdate()->nullOnDelete();
            $table->string('table_label')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->string('customer_name')->nullable();

            $table->string('status', 16)->default('new');
            $table->unsignedInteger('total_amount')->default(0);

            $table->string('payment_method', 16)->default('qris');
            $table->string('payment_status', 16)->default('unpaid');
            $table->string('payment_ref')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
