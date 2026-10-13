<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_logs: every email attempt from PlatformMailService (kept 90 days) — super admin
 * Pengaturan Email › Riwayat. `payload` = encrypted serialized mailable, only for failed sends
 * (so "Kirim ulang" works). users.email_preferences: {"marketing": false} after one-click unsubscribe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to_email', 255);
            $table->string('subject', 255)->default('');
            $table->string('mailable', 120)->default('');
            $table->string('category', 20)->default('transactional');
            $table->string('status', 12); // sent | failed | skipped
            $table->text('error')->nullable();
            $table->string('message_id', 255)->nullable();
            $table->longText('payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->timestamps();
            $table->index('to_email');
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });

        if (!Schema::hasColumn('users', 'email_preferences')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('email_preferences')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
        if (Schema::hasColumn('users', 'email_preferences')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('email_preferences');
            });
        }
    }
};
