<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_assets', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('original_name');
            $table->index(['organization_id', 'content_hash'], 'file_assets_org_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::table('file_assets', function (Blueprint $table) {
            $table->dropIndex('file_assets_org_hash_idx');
            $table->dropColumn('content_hash');
        });
    }
};
