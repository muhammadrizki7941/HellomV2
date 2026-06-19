<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_articles', function (Blueprint $table) {
            $table->string('meta_title')->nullable()->after('slug');
            $table->string('meta_description', 500)->nullable()->after('meta_title');
            $table->string('meta_keywords')->nullable()->after('meta_description');
            $table->string('og_image', 500)->nullable()->after('meta_keywords');
            $table->string('author', 120)->nullable()->after('og_image');
        });
    }

    public function down(): void
    {
        Schema::table('landing_articles', function (Blueprint $table) {
            $table->dropColumn(['meta_title', 'meta_description', 'meta_keywords', 'og_image', 'author']);
        });
    }
};
