<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('organizations', 'business_type')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->string('business_type', 32)->default('fnb')->after('status');
            });
        }

        if (!Schema::hasColumn('organizations', 'enabled_modules')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->json('enabled_modules')->nullable()->after('business_type');
            });
        }

        DB::table('organizations')
            ->whereNull('enabled_modules')
            ->update(['enabled_modules' => json_encode(['tables_selforder'], JSON_THROW_ON_ERROR)]);
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['enabled_modules', 'business_type'],
            fn (string $column) => Schema::hasColumn('organizations', $column)
        ));

        if ($columns !== []) {
            Schema::table('organizations', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
