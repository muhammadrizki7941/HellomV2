<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flagship SaaS apps (POS, Landing Page Builder, …) are shown on the public /aplikasi
 * showcase instead of the regular /produk catalog. The super admin decides which
 * products are flagship and uploads their banners.
 *
 *  - is_flagship:       shown on /aplikasi, hidden from /produk
 *  - flagship_app:      optional link to a built-in SaaS app (frontend data/apps.ts slug:
 *                       "pos", "landing-page-builder") for its benefits and "Coba" button
 *  - banner_url:        wide banner (desktop), banner_mobile_url: portrait banner (phones)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_products', function (Blueprint $table) {
            $table->boolean('is_flagship')->default(false)->after('is_featured');
            $table->string('flagship_app', 64)->nullable()->after('is_flagship');
            $table->string('banner_url')->nullable()->after('thumbnail_url');
            $table->string('banner_mobile_url')->nullable()->after('banner_url');
            $table->index(['is_published', 'is_flagship']);
        });

        // The two built-in SaaS apps, when they exist in the catalog under their seeded slugs.
        DB::table('digital_products')->where('slug', 'pos-kasir-digital')
            ->update(['is_flagship' => true, 'flagship_app' => 'pos']);
        DB::table('digital_products')->where('slug', 'landing-page-builder')
            ->update(['is_flagship' => true, 'flagship_app' => 'landing-page-builder']);
    }

    public function down(): void
    {
        Schema::table('digital_products', function (Blueprint $table) {
            $table->dropIndex(['is_published', 'is_flagship']);
            $table->dropColumn(['is_flagship', 'flagship_app', 'banner_url', 'banner_mobile_url']);
        });
    }
};
