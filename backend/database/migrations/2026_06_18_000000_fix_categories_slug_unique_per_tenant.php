<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The categories table was created with a GLOBAL unique index on `slug`.
 * Every newly registered organization seeds the same default category slugs
 * (food, minuman, dessert), so only the first organization could register.
 * Every subsequent registration hit a duplicate-key violation that rolled back
 * the transaction and returned HTTP 500.
 *
 * This migration makes the slug unique PER TENANT instead of globally.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('categories')) {
            return;
        }

        // Drop the legacy global unique index on `slug` if it exists.
        if ($this->indexExists('categories', 'categories_slug_unique')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropUnique('categories_slug_unique');
            });
        }

        // Add a composite unique (tenant_id, slug) if it does not exist and the
        // current data has no conflicting duplicates.
        if (
            Schema::hasColumn('categories', 'tenant_id')
            && !$this->indexExists('categories', 'categories_tenant_slug_unique')
            && !$this->hasTenantSlugDuplicates()
        ) {
            Schema::table('categories', function (Blueprint $table) {
                $table->unique(['tenant_id', 'slug'], 'categories_tenant_slug_unique');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('categories')) {
            return;
        }

        if ($this->indexExists('categories', 'categories_tenant_slug_unique')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropUnique('categories_tenant_slug_unique');
            });
        }

        if (!$this->indexExists('categories', 'categories_slug_unique')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->unique('slug', 'categories_slug_unique');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();

        $result = DB::selectOne(
            'SELECT COUNT(1) AS total FROM information_schema.statistics '
            . 'WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $index]
        );

        return (int) ($result->total ?? 0) > 0;
    }

    private function hasTenantSlugDuplicates(): bool
    {
        $duplicate = DB::table('categories')
            ->select('tenant_id', 'slug', DB::raw('COUNT(*) AS total'))
            ->groupBy('tenant_id', 'slug')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        return $duplicate !== null;
    }
};
