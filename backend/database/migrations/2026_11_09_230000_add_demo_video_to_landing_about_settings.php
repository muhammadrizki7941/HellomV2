<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_about_settings', function (Blueprint $table) {
            $table->boolean('demo_video_enabled')->default(false);
            $table->string('demo_video_url', 2048)->nullable();
            $table->string('demo_video_title')->nullable();
            $table->text('demo_video_description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('landing_about_settings', function (Blueprint $table) {
            $table->dropColumn(['demo_video_enabled', 'demo_video_url', 'demo_video_title', 'demo_video_description']);
        });
    }
};
