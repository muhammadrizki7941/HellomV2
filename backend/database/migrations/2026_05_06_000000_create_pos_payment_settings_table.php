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
        Schema::create('pos_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();

            // Tunai
            $table->boolean('cash_enabled')->default(true);
            $table->string('cash_label')->default('Tunai');

            // Transfer Bank
            $table->boolean('transfer_enabled')->default(false);
            $table->string('transfer_bank_name')->nullable();
            $table->string('transfer_account_number')->nullable();
            $table->string('transfer_account_name')->nullable();

            // GoPay
            $table->boolean('gopay_enabled')->default(false);
            $table->string('gopay_number')->nullable();
            $table->string('gopay_name')->nullable();

            // Dana
            $table->boolean('dana_enabled')->default(false);
            $table->string('dana_number')->nullable();
            $table->string('dana_name')->nullable();

            // QRIS
            $table->boolean('qris_enabled')->default(false);
            $table->string('qris_image_path')->nullable();
            $table->string('qris_label')->default('QRIS');

            $table->timestamps();
            $table->unique('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_payment_settings');
    }
};