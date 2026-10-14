<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Buat kata sandi sendiri" links for accounts created by guest checkout (password broker
 * `account_setup`, 7 days, single use). Separate from password_reset_tokens so a normal
 * reset link keeps its 60-minute lifetime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_setup_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_setup_tokens');
    }
};
