<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_stats', function (Blueprint $table) {
            $table->unsignedBigInteger('views_count')->default(0)->after('published_count');
            $table->timestamp('last_viewed_at')->nullable()->after('views_count');
        });
    }

    public function down(): void
    {
        Schema::table('landing_stats', function (Blueprint $table) {
            $table->dropColumn(['views_count', 'last_viewed_at']);
        });
    }
};
