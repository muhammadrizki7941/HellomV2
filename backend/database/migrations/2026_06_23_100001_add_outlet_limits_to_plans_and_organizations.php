<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outlet quota. Each plan defines how many outlets an organization may run
 * (tiered pricing). Hellom owner can override the cap per organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'max_outlets')) {
                $table->unsignedInteger('max_outlets')->default(1)->after('duration_days');
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'max_outlets_override')) {
                $table->unsignedInteger('max_outlets_override')->nullable()->after('pos_provisioned_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'max_outlets')) {
                $table->dropColumn('max_outlets');
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'max_outlets_override')) {
                $table->dropColumn('max_outlets_override');
            }
        });
    }
};
