<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['categories', 'products', 'dining_tables', 'orders'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $tableBlueprint) {
                $tableBlueprint->string('tenant_id', 100)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        $tables = ['categories', 'products', 'dining_tables', 'orders'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $tableBlueprint) {
                $tableBlueprint->unsignedBigInteger('tenant_id')->nullable()->change();
            });
        }
    }
};