<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_settings', function (Blueprint $table) {
            $table->string('home_banner_media_path', 255)->nullable()->after('favicon_path');
            $table->string('home_banner_media_mime', 80)->nullable()->after('home_banner_media_path');
        });
    }

    public function down(): void
    {
        Schema::table('brand_settings', function (Blueprint $table) {
            $table->dropColumn(['home_banner_media_mime', 'home_banner_media_path']);
        });
    }
};
