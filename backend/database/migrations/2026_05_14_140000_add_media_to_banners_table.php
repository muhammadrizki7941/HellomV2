<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            if (!Schema::hasColumn('banners', 'media_type')) {
                $table->string('media_type')->nullable()->after('image');
            }
            if (!Schema::hasColumn('banners', 'video_url')) {
                $table->string('video_url')->nullable()->after('media_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            if (Schema::hasColumn('banners', 'video_url')) {
                $table->dropColumn('video_url');
            }
            if (Schema::hasColumn('banners', 'media_type')) {
                $table->dropColumn('media_type');
            }
        });
    }
};
