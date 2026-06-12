<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('track_stock')->default(false)->after('is_available');
            $table->unsignedInteger('stock')->nullable()->after('track_stock');

            $table->boolean('is_package')->default(false)->after('stock');
            $table->boolean('show_as_banner')->default(false)->after('is_package');
            $table->string('banner_title')->nullable()->after('show_as_banner');
            $table->string('banner_subtitle')->nullable()->after('banner_title');
            $table->dateTime('banner_starts_at')->nullable()->after('banner_subtitle');
            $table->dateTime('banner_ends_at')->nullable()->after('banner_starts_at');

            $table->index(['is_package', 'show_as_banner']);
            $table->index(['track_stock', 'stock']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_package', 'show_as_banner']);
            $table->dropIndex(['track_stock', 'stock']);

            $table->dropColumn([
                'track_stock',
                'stock',
                'is_package',
                'show_as_banner',
                'banner_title',
                'banner_subtitle',
                'banner_starts_at',
                'banner_ends_at',
            ]);
        });
    }
};
