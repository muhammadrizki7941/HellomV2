<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'logo_path')) {
                $table->string('logo_path')->nullable()->after('slug');
            }

            if (!Schema::hasColumn('organizations', 'address')) {
                $table->string('address')->nullable()->after('logo_path');
            }

            if (!Schema::hasColumn('organizations', 'phone')) {
                $table->string('phone', 20)->nullable()->after('address');
            }

            if (!Schema::hasColumn('organizations', 'email')) {
                $table->string('email')->nullable()->after('phone');
            }

            if (!Schema::hasColumn('organizations', 'description')) {
                $table->text('description')->nullable()->after('email');
            }

            if (!Schema::hasColumn('organizations', 'website')) {
                $table->string('website')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $dropColumns = [];

            if (Schema::hasColumn('organizations', 'logo_path')) {
                $dropColumns[] = 'logo_path';
            }

            if (Schema::hasColumn('organizations', 'address')) {
                $dropColumns[] = 'address';
            }

            if (Schema::hasColumn('organizations', 'phone')) {
                $dropColumns[] = 'phone';
            }

            if (Schema::hasColumn('organizations', 'email')) {
                $dropColumns[] = 'email';
            }

            if (Schema::hasColumn('organizations', 'description')) {
                $dropColumns[] = 'description';
            }

            if (Schema::hasColumn('organizations', 'website')) {
                $dropColumns[] = 'website';
            }

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};