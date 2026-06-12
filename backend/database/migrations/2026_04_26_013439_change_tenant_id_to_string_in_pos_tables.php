<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function dropForeignIfExists(string $table, string $column): void
    {
        $foreignKeyName = $table . '_' . $column . '_foreign';

        // Use raw SQL to check and drop foreign key
        $exists = \DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?", [$table, $foreignKeyName]);

        if (!empty($exists)) {
            \DB::statement("ALTER TABLE `$table` DROP FOREIGN KEY `$foreignKeyName`");
        }
    }
    public function up(): void
    {
        // Drop foreign key constraints (if they exist) FIRST
        $this->dropForeignIfExists('orders', 'tenant_id');
        $this->dropForeignIfExists('categories', 'tenant_id');
        $this->dropForeignIfExists('products', 'tenant_id');
        $this->dropForeignIfExists('dining_tables', 'tenant_id');
        $this->dropForeignIfExists('site_promotions', 'tenant_id');

        // Change to VARCHAR(100) FIRST (with nullable to allow existing INT values to be cast)
        foreach (['orders', 'categories', 'products', 'dining_tables'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('tenant_id', 100)->nullable()->change();
            });
        }

        // Now convert integer tenant_id to string slugs
        $organizations = \DB::table('organizations')->get(['id', 'pos_tenant_slug', 'slug']);

        // Update data using raw SQL after changing column type
        foreach (['orders', 'categories', 'products', 'dining_tables', 'site_promotions'] as $table) {
            $caseStatements = $organizations->map(function ($org) {
                $tenantSlug = $org->pos_tenant_slug ?: $org->slug;
                return "WHEN CAST(tenant_id AS UNSIGNED) = {$org->id} THEN '$tenantSlug'";
            })->join(' ');

            \DB::statement("UPDATE `$table` SET tenant_id = CASE $caseStatements ELSE 'unknown-tenant' END WHERE tenant_id IS NOT NULL AND tenant_id REGEXP '^[0-9]+$'");
        }
    }

    public function down(): void
    {
        // Revert back to INT (dangerous - may lose data)
        foreach (['orders', 'categories', 'products', 'dining_tables'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->change();
            });
        }
    }
};