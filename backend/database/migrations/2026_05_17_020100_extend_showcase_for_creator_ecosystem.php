<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('showcase_portfolios', function (Blueprint $table) {
            if (!Schema::hasColumn('showcase_portfolios', 'slug')) {
                $table->string('slug')->nullable()->unique()->after('title');
            }
            if (!Schema::hasColumn('showcase_portfolios', 'gallery_images')) {
                $table->json('gallery_images')->nullable()->after('thumbnail_url');
            }
            if (!Schema::hasColumn('showcase_portfolios', 'project_year')) {
                $table->string('project_year', 20)->nullable()->after('client_name');
            }
            if (!Schema::hasColumn('showcase_portfolios', 'project_url')) {
                $table->string('project_url', 500)->nullable()->after('project_year');
            }
            if (!Schema::hasColumn('showcase_portfolios', 'full_description')) {
                $table->longText('full_description')->nullable()->after('description');
            }
            if (!Schema::hasColumn('showcase_portfolios', 'tech_stack')) {
                $table->json('tech_stack')->nullable()->after('full_description');
            }
            if (!Schema::hasColumn('showcase_portfolios', 'is_featured')) {
                $table->boolean('is_featured')->default(true)->after('is_published');
            }
        });
    }

    public function down(): void
    {
        Schema::table('showcase_portfolios', function (Blueprint $table) {
            foreach (['slug', 'gallery_images', 'project_year', 'project_url', 'full_description', 'tech_stack', 'is_featured'] as $column) {
                if (Schema::hasColumn('showcase_portfolios', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
