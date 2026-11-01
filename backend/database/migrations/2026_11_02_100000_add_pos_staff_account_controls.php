<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('username', 50)->nullable()->unique()->after('email');
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
        });

        Schema::table('pos_staff', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->after('linked_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Tambahkan email pada akun staf tanpa email sebelum rollback migrasi akun POS.');
        }

        Schema::table('pos_staff', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_user_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'must_change_password', 'last_login_at']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
