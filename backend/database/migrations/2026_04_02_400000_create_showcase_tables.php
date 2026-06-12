<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('showcase_portfolios', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('description', 500)->nullable();
            $table->string('video_url', 500)->comment('URL to uploaded video or external embed');
            $table->string('thumbnail_url', 500)->nullable();
            $table->string('client_name', 255)->nullable();
            $table->string('category', 100)->nullable()->comment('e.g. landing-page, pos, ecommerce');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['is_published', 'sort_order']);
        });

        Schema::create('showcase_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('logo_url', 500);
            $table->string('website_url', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['is_published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('showcase_clients');
        Schema::dropIfExists('showcase_portfolios');
    }
};
