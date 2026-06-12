<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            if (!Schema::hasColumn('banners', 'cta_text')) {
                $table->string('cta_text')->nullable()->after('subtitle');
            }
            if (!Schema::hasColumn('banners', 'badge')) {
                $table->string('badge')->nullable()->after('cta_text');
            }
            if (!Schema::hasColumn('banners', 'background_from')) {
                $table->string('background_from')->nullable()->after('badge');
            }
            if (!Schema::hasColumn('banners', 'background_to')) {
                $table->string('background_to')->nullable()->after('background_from');
            }
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            if (Schema::hasColumn('banners', 'background_to')) {
                $table->dropColumn('background_to');
            }
            if (Schema::hasColumn('banners', 'background_from')) {
                $table->dropColumn('background_from');
            }
            if (Schema::hasColumn('banners', 'badge')) {
                $table->dropColumn('badge');
            }
            if (Schema::hasColumn('banners', 'cta_text')) {
                $table->dropColumn('cta_text');
            }
        });
    }
};
