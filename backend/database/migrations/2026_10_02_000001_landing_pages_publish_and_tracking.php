<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fase 4 — builder & public pages (docs/AUDIT_LANDING_BUILDER.md). Additive only.
 *
 *  - organization_landing_pages  draft document (edited, autosaved) + pointer to the published
 *                                version; home page flag; SEO fields
 *  - landing_page_versions       full document snapshot per publish (restore = copy into draft)
 *  - organizations               landing_username (public URL, separate from the org slug the POS uses)
 *  - landing_products            slug for /{username}/{product}
 *  - landing_page_orders         ad attribution (UTM, click ids) + purchase event bookkeeping
 *  - landing_tracking_settings   seller pixel / analytics ids (CAPI token encrypted)
 *  - landing_stats_daily         light traffic stats (aggregated per day, no raw visitor rows)
 *  - plans                       max_landing_pages (paid multi-page quota, set by super admin)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_landing_pages', function (Blueprint $table): void {
            $table->json('draft_document')->nullable()->after('content');
            $table->unsignedInteger('draft_revision')->default(0)->after('draft_document');
            $table->timestamp('draft_saved_at')->nullable()->after('draft_revision');
            $table->unsignedBigInteger('published_version_id')->nullable()->after('draft_saved_at');
            $table->boolean('is_home')->default(false)->after('status');
            $table->string('seo_title', 120)->nullable();
            $table->string('seo_description', 300)->nullable();
            $table->string('seo_image', 500)->nullable();
        });

        Schema::table('landing_page_versions', function (Blueprint $table): void {
            $table->json('document')->nullable()->after('content');
            $table->foreignId('created_by_user_id')->nullable()->after('document')->constrained('users')->nullOnDelete();
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('landing_username', 40)->nullable()->unique();
        });

        Schema::table('landing_products', function (Blueprint $table): void {
            $table->string('slug', 120)->nullable()->after('public_id');
            $table->unique(['organization_id', 'slug'], 'landing_products_org_slug_unique');
        });

        Schema::table('landing_page_orders', function (Blueprint $table): void {
            $table->json('attribution')->nullable();
            $table->string('source', 60)->nullable();              // utm_source or referrer host or "langsung", for reports
            $table->timestamp('purchase_tracked_at')->nullable();  // browser Purchase event fired (never twice)
            $table->timestamp('capi_sent_at')->nullable();         // server-side Meta Conversions API
            $table->index(['organization_id', 'source'], 'lpo_org_source_idx');
        });

        Schema::create('landing_tracking_settings', function (Blueprint $table): void {
            $table->foreignId('organization_id')->primary()->constrained('organizations')->cascadeOnDelete();
            $table->string('meta_pixel_id', 30)->nullable();
            $table->text('meta_capi_token')->nullable();           // encrypted, server only
            $table->string('meta_test_event_code', 30)->nullable();
            $table->string('ga4_id', 30)->nullable();
            $table->string('google_ads_id', 30)->nullable();
            $table->string('google_ads_label', 60)->nullable();
            $table->string('tiktok_pixel_id', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('landing_stats_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedBigInteger('landing_page_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->date('day');
            $table->string('metric', 30);                           // visit|product_view|click|checkout_start
            $table->string('dimension', 120)->default('');          // source for visits, button label for clicks
            $table->unsignedInteger('count')->default(0);
            $table->unique(['organization_id', 'landing_page_id', 'product_id', 'day', 'metric', 'dimension'], 'landing_stats_daily_unique');
            $table->index(['organization_id', 'day']);
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedInteger('max_landing_pages')->nullable()->after('max_outlets');
        });

        // Product slugs from names (unique per shop).
        DB::table('landing_products')->orderBy('id')->each(function ($product): void {
            $base = Str::slug((string) $product->name) ?: 'produk';
            $base = Str::limit($base, 100, '');
            $slug = $base;
            for ($i = 2; DB::table('landing_products')->where('organization_id', $product->organization_id)->where('slug', $slug)->exists(); $i++) {
                $slug = $base . '-' . $i;
            }
            DB::table('landing_products')->where('id', $product->id)->update(['slug' => $slug]);
        });

        // The main page of each shop: the one the public URL showed until now.
        foreach (DB::table('organization_landing_pages')->select('organization_id')->distinct()->pluck('organization_id') as $orgId) {
            $home = DB::table('organization_landing_pages')->where('organization_id', $orgId)
                ->orderByRaw("CASE WHEN status = 'published' THEN 0 ELSE 1 END")
                ->orderByRaw("CASE WHEN slug = 'landing-page' THEN 0 ELSE 1 END")
                ->orderByDesc('published_at')->orderByDesc('id')->value('id');
            DB::table('organization_landing_pages')->where('id', $home)->update(['is_home' => true]);
        }
        // Drafts/published snapshots are built from the live blocks by `landing:documents-backfill`
        // (needs the block schema code, so it is a command, not SQL here).
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('max_landing_pages');
        });
        Schema::dropIfExists('landing_stats_daily');
        Schema::dropIfExists('landing_tracking_settings');
        Schema::table('landing_page_orders', function (Blueprint $table): void {
            $table->dropIndex('lpo_org_source_idx');
            $table->dropColumn(['attribution', 'source', 'purchase_tracked_at', 'capi_sent_at']);
        });
        Schema::table('landing_products', function (Blueprint $table): void {
            $table->dropUnique('landing_products_org_slug_unique');
            $table->dropColumn('slug');
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropUnique(['landing_username']);
            $table->dropColumn('landing_username');
        });
        Schema::table('landing_page_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('document');
        });
        Schema::table('organization_landing_pages', function (Blueprint $table): void {
            $table->dropColumn(['draft_document', 'draft_revision', 'draft_saved_at', 'published_version_id', 'is_home', 'seo_title', 'seo_description', 'seo_image']);
        });
    }
};
