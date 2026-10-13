<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** users.lifecycle_emails: which one-time lifecycle emails went out, e.g. {"verify_reminder": "2026-10-15T…"}. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'lifecycle_emails')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('lifecycle_emails')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'lifecycle_emails')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('lifecycle_emails');
            });
        }
    }
};
