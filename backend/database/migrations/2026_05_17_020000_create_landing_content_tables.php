<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_about_settings', function (Blueprint $table) {
            $table->id();
            $table->string('title')->default('Membangun dengan strategi, berkarya dengan estetika.');
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('years_experience')->default(5);
            $table->unsignedInteger('projects_completed')->default(100);
            $table->unsignedInteger('happy_clients')->default(50);
            $table->string('support_label')->default('24/7');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('landing_services', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->string('short_description', 500)->nullable();
            $table->text('long_description')->nullable();
            $table->string('featured_image', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('landing_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('thumbnail', 500)->nullable();
            $table->longText('content')->nullable();
            $table->string('excerpt', 500)->nullable();
            $table->string('category', 100)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('read_time')->default(5);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'is_featured', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_articles');
        Schema::dropIfExists('landing_services');
        Schema::dropIfExists('landing_about_settings');
    }
};
