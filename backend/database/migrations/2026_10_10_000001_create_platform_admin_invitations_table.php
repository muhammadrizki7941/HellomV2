<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations to become a platform super admin (Super admin › Pengaturan › Tim admin). The link
 * in the email carries the token; only its SHA-256 hash is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admin_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 190);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['email', 'accepted_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_invitations');
    }
};
