<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hellom Page statistics per link and per source (Fase 6): a click now also stores which link
 * it was (`item` = block id, "social:instagram", "wa-float") and where the visitor came from
 * (`source`). Additive: old rows keep '' in both columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_stats_daily', function (Blueprint $table): void {
            $table->string('item', 64)->default('')->after('dimension');
            $table->string('source', 60)->default('')->after('item');
        });
        Schema::table('landing_stats_daily', function (Blueprint $table): void {
            $table->dropUnique('landing_stats_daily_unique');
            $table->unique(['organization_id', 'landing_page_id', 'product_id', 'day', 'metric', 'dimension', 'item', 'source'], 'landing_stats_daily_unique');
        });
    }

    public function down(): void
    {
        // Fold rows that only differ by item/source back together before the narrower key returns.
        $groups = DB::table('landing_stats_daily')
            ->selectRaw('organization_id, landing_page_id, product_id, day, metric, dimension, SUM(count) AS total, MIN(id) AS keep_id, COUNT(*) AS n')
            ->groupBy('organization_id', 'landing_page_id', 'product_id', 'day', 'metric', 'dimension')
            ->havingRaw('COUNT(*) > 1')->get();
        foreach ($groups as $g) {
            DB::table('landing_stats_daily')->where('id', $g->keep_id)->update(['count' => $g->total]);
            DB::table('landing_stats_daily')
                ->where(['organization_id' => $g->organization_id, 'landing_page_id' => $g->landing_page_id, 'product_id' => $g->product_id, 'day' => $g->day, 'metric' => $g->metric, 'dimension' => $g->dimension])
                ->where('id', '!=', $g->keep_id)->delete();
        }
        Schema::table('landing_stats_daily', function (Blueprint $table): void {
            $table->dropUnique('landing_stats_daily_unique');
            $table->unique(['organization_id', 'landing_page_id', 'product_id', 'day', 'metric', 'dimension'], 'landing_stats_daily_unique');
        });
        Schema::table('landing_stats_daily', function (Blueprint $table): void {
            $table->dropColumn(['item', 'source']);
        });
    }
};
