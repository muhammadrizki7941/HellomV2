<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('organizations', 'pos_onboarding_completed_at')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->timestamp('pos_onboarding_completed_at')->nullable()->after('enabled_modules');
            });
        }

        DB::table('organizations')
            ->whereNull('pos_onboarding_completed_at')
            ->update(['pos_onboarding_completed_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('organizations', 'pos_onboarding_completed_at')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('pos_onboarding_completed_at');
            });
        }
    }
};
