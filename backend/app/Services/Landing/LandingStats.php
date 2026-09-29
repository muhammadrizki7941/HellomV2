<?php

namespace App\Services\Landing;

use App\Models\LandingPageOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Light Hellom Page statistics (Fase 4, audit LB-33): daily counters per shop/page/product,
 * no raw visitor rows and no cookies. A visit is counted once per visitor per page per day
 * (hash of IP + user agent + day, kept only as a cache key).
 */
final class LandingStats
{
    public const METRICS = ['visit', 'product_view', 'click', 'checkout_start'];

    public function record(int $organizationId, string $metric, ?int $pageId, ?int $productId, string $dimension, string $visitor): void
    {
        if (!in_array($metric, self::METRICS, true)) {
            return;
        }
        $day = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        if (in_array($metric, ['visit', 'product_view'], true)) {
            $key = 'lstat:' . sha1($visitor . '|' . $metric . '|' . $pageId . '|' . $productId . '|' . $day);
            if (!Cache::add($key, 1, now()->addDay())) {
                return; // already counted today
            }
        }
        DB::statement(
            'INSERT INTO landing_stats_daily (organization_id, landing_page_id, product_id, day, metric, dimension, count) VALUES (?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1',
            // 0 instead of NULL: MySQL unique keys never match NULLs, so upserts would add rows.
            [$organizationId, (int) $pageId, (int) $productId, $day, $metric, Str::limit($dimension, 120, '')]
        );
    }

    /** "instagram", "google", "langsung"… from utm_source or a referrer host. */
    public static function sourceLabel(?string $source): string
    {
        $source = strtolower(trim((string) $source));
        if ($source === '') {
            return 'langsung';
        }
        $map = ['instagram' => 'instagram', 'facebook' => 'facebook', 'fb.' => 'facebook', 'tiktok' => 'tiktok', 'google' => 'google', 'youtube' => 'youtube',
            'whatsapp' => 'whatsapp', 'wa.me' => 'whatsapp', 't.co' => 'x', 'twitter' => 'x', 'threads' => 'threads', 'linktr' => 'linktree', 'telegram' => 't.me'];
        foreach ($map as $needle => $label) {
            if (str_contains($source, $needle)) {
                return $label;
            }
        }

        return Str::limit(preg_replace('/^www\./', '', $source) ?? $source, 60, '');
    }

    /**
     * Report for the seller dashboard.
     *
     * @return array<string, mixed>
     */
    public function report(int $organizationId, int $days): array
    {
        $from = CarbonImmutable::now('Asia/Jakarta')->subDays($days - 1)->toDateString();
        $rows = DB::table('landing_stats_daily')->where('organization_id', $organizationId)->where('day', '>=', $from);

        $totals = (clone $rows)->selectRaw('metric, SUM(count) AS total')->groupBy('metric')->pluck('total', 'metric');
        $daily = (clone $rows)->where('metric', 'visit')->selectRaw('day, SUM(count) AS total')->groupBy('day')->orderBy('day')->pluck('total', 'day');
        $sources = (clone $rows)->where('metric', 'visit')->selectRaw('dimension, SUM(count) AS total')->groupBy('dimension')->orderByDesc('total')->limit(8)->get()
            ->map(fn ($r) => ['source' => $r->dimension ?: 'langsung', 'visits' => (int) $r->total]);
        $clicks = (clone $rows)->where('metric', 'click')->selectRaw('dimension, SUM(count) AS total')->groupBy('dimension')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($r) => ['label' => $r->dimension ?: '(tanpa label)', 'clicks' => (int) $r->total]);

        $productViews = (clone $rows)->where('product_id', '>', 0)->whereIn('metric', ['product_view', 'checkout_start'])
            ->selectRaw('product_id, metric, SUM(count) AS total')->groupBy('product_id', 'metric')->get();
        $sales = LandingPageOrder::query()->where('organization_id', $organizationId)->whereNotNull('product_id')
            ->whereIn('status', [LandingPageOrder::STATUS_PAID, LandingPageOrder::STATUS_FULFILLED])
            ->where('paid_at', '>=', CarbonImmutable::parse($from, 'Asia/Jakarta')->utc())
            ->selectRaw('product_id, COUNT(*) AS orders, SUM(amount) AS revenue')->groupBy('product_id')->get()->keyBy('product_id');
        $products = DB::table('landing_products')->where('organization_id', $organizationId)->pluck('name', 'id');
        $perProduct = collect($products)->map(function ($name, $id) use ($productViews, $sales) {
            $views = (int) $productViews->where('product_id', $id)->where('metric', 'product_view')->sum('total');
            $starts = (int) $productViews->where('product_id', $id)->where('metric', 'checkout_start')->sum('total');
            $orders = (int) ($sales[$id]->orders ?? 0);

            return ['product_id' => $id, 'name' => $name, 'views' => $views, 'checkout_starts' => $starts, 'orders' => $orders,
                'revenue' => (int) ($sales[$id]->revenue ?? 0), 'conversion' => $starts > 0 ? round($orders / $starts * 100, 1) : null];
        })->filter(fn ($r) => $r['views'] + $r['checkout_starts'] + $r['orders'] > 0)->sortByDesc('orders')->values();

        $salesBySource = LandingPageOrder::query()->where('organization_id', $organizationId)
            ->whereIn('status', [LandingPageOrder::STATUS_PAID, LandingPageOrder::STATUS_FULFILLED])
            ->where('paid_at', '>=', CarbonImmutable::parse($from, 'Asia/Jakarta')->utc())
            ->selectRaw("COALESCE(source, 'langsung') AS src, COUNT(*) AS orders, SUM(amount) AS revenue")->groupBy('src')->orderByDesc('revenue')->get()
            ->map(fn ($r) => ['source' => $r->src, 'orders' => (int) $r->orders, 'revenue' => (int) $r->revenue]);

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $d = CarbonImmutable::parse($from)->addDays($i)->toDateString();
            $series[] = ['date' => $d, 'visits' => (int) ($daily[$d] ?? 0)];
        }

        return [
            'days' => $days,
            'totals' => ['visits' => (int) ($totals['visit'] ?? 0), 'product_views' => (int) ($totals['product_view'] ?? 0),
                'clicks' => (int) ($totals['click'] ?? 0), 'checkout_starts' => (int) ($totals['checkout_start'] ?? 0)],
            'daily' => $series,
            'sources' => $sources,
            'clicks' => $clicks,
            'products' => $perProduct,
            'sales_by_source' => $salesBySource,
        ];
    }
}
