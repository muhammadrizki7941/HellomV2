<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web push: one row per browser/device that allowed notifications (PushManager subscription).
 * The endpoint is long, so uniqueness goes through its SHA-256.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 255);
            $table->string('auth', 64);
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
