<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_intents', function (Blueprint $table): void {
            $table->string('status_token_hash', 64)->nullable()->unique();
            $table->timestamp('guest_setup_email_sent_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->json('marketing_attribution')->nullable();
        });

        Schema::create('subscription_checkout_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checkout_intent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30)->default('ipaymu');
            $table->string('reference_id', 100);
            $table->string('transaction_id', 100)->nullable()->unique();
            $table->string('session_id', 100)->nullable();
            $table->string('method', 30);
            $table->string('channel', 40);
            $table->string('channel_label', 100)->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('amount');
            $table->string('payment_number', 100)->nullable();
            $table->text('qr_string')->nullable();
            $table->text('qr_image_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('gateway_metadata')->nullable();
            $table->timestamps();

            $table->index(['checkout_intent_id', 'status']);
            $table->index(['invoice_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_checkout_payments');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('marketing_attribution');
        });
        Schema::table('checkout_intents', function (Blueprint $table): void {
            $table->dropUnique(['status_token_hash']);
            $table->dropColumn(['status_token_hash', 'guest_setup_email_sent_at']);
        });
    }
};
