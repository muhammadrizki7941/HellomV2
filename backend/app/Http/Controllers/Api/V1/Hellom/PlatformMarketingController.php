<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\PlatformMarketingEvent;
use App\Models\AppCatalog;
use App\Models\DigitalProduct;
use App\Models\SystemSetting;
use App\Services\PlatformMarketing;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlatformMarketingController extends BaseApiController
{
    public function publicConfig(PlatformMarketing $marketing)
    {
        $c = $marketing->config();
        return $this->ok(['enabled' => $c['enabled'], 'pixel_id' => $c['enabled'] && $c['meta_enabled'] ? $c['pixel_id'] : '']);
    }

    public function track(Request $request, PlatformMarketing $marketing)
    {
        $data = $request->validate(['event_name' => 'required|in:PageView,ViewContent', 'event_id' => 'required|uuid', 'marketing' => 'required|array']);
        if (preg_match('/bot|crawler|spider|headless/i', (string) $request->userAgent())) return $this->ok(null);
        $event = $marketing->record($data['event_name'], 'web_' . $data['event_id'], $marketing->context());
        return $this->ok(['recorded' => $event !== null]);
    }

    public function settings(PlatformMarketing $marketing) { return $this->ok($marketing->config()); }

    public function dashboardPromotions()
    {
        $selected = json_decode((string) SystemSetting::get('dashboard_promoted_app_slugs', '[]'), true);
        $selectedApps = is_array($selected) && array_is_list($selected)
            ? $selected
            : (is_array($selected) ? ($selected['apps'] ?? []) : []);
        $selectedProducts = is_array($selected) && !array_is_list($selected)
            ? ($selected['products'] ?? [])
            : [];

        return $this->ok([
            'apps' => AppCatalog::query()->where('is_active', true)->orderBy('name')->get(['slug', 'name']),
            'products' => DigitalProduct::query()
                ->published()
                ->where('is_flagship', true)
                ->where('type', 'paid')
                ->where('price', '>', 0)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'slug', 'name', 'price', 'type', 'thumbnail_url', 'flagship_app']),
            'selected_slugs' => array_values(array_unique(array_filter($selectedApps, 'is_string'))),
            'selected_product_ids' => array_values(array_unique(array_filter($selectedProducts, 'is_numeric'))),
        ]);
    }

    public function saveDashboardPromotions(Request $request)
    {
        $data = $request->validate([
            'app_slugs' => ['present', 'array', 'max:20'],
            'app_slugs.*' => ['required', 'string', 'max:100', 'distinct'],
            'product_ids' => ['sometimes', 'array', 'max:20'],
            'product_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $requested = $data['app_slugs'];
        $requestedProductIds = array_map('intval', $data['product_ids'] ?? []);
        $activeSlugs = AppCatalog::query()
            ->where('is_active', true)
            ->whereIn('slug', $requested)
            ->pluck('slug')
            ->all();
        $publishableProductIds = $requestedProductIds === []
            ? []
            : DigitalProduct::query()
                ->published()
                ->where('is_flagship', true)
                ->where('type', 'paid')
                ->where('price', '>', 0)
                ->whereIn('id', $requestedProductIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        if (count($activeSlugs) !== count($requested) || count($publishableProductIds) !== count($requestedProductIds)) {
            return $this->fail('Pilih aplikasi yang masih aktif saja.', ['code' => 'INVALID_PROMOTED_APPS'], 422);
        }

        SystemSetting::set('dashboard_promoted_app_slugs', json_encode([
            'apps' => array_values($requested),
            'products' => array_values($requestedProductIds),
        ]));
        $this->adminAudit($request, 'admin.marketing.dashboard_promotions', 'system_setting', null, [
            'app_slugs' => $requested,
            'product_ids' => $requestedProductIds,
        ]);

        return $this->ok([
            'selected_slugs' => array_values($requested),
            'selected_product_ids' => array_values($requestedProductIds),
        ], 'Promosi aplikasi dashboard berhasil disimpan.');
    }

    public function save(Request $request, PlatformMarketing $marketing)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean', 'meta_enabled' => 'required|boolean', 'pixel_id' => ['nullable', 'regex:/^\d{5,25}$/'],
            'token' => 'nullable|string|max:2000', 'clear_token' => 'sometimes|boolean', 'test_event_code' => 'nullable|string|max:100',
            'api_version' => ['required', 'regex:/^v\d{2}\.0$/'],
        ]);
        if ($data['meta_enabled'] && empty($data['pixel_id'])) return $this->fail('Isi Pixel/Dataset ID terlebih dahulu.', null, 422);
        $result = $marketing->save($data);
        $this->adminAudit($request, 'admin.marketing.settings', 'marketing', null, ['enabled' => $data['enabled'], 'meta_enabled' => $data['meta_enabled']]);
        return $this->ok($result);
    }

    public function test(Request $request, PlatformMarketing $marketing)
    {
        $config = $marketing->config();
        if (!$config['enabled'] || !$config['meta_enabled'] || !$config['pixel_id'] || !$config['token_configured'] || !$config['test_event_code']) {
            return $this->fail('Aktifkan integrasi dan isi Pixel ID, token serta Test Event Code dari Events Manager sebelum tes.', null, 422);
        }
        $event = $marketing->record('PageView', 'test_' . Str::uuid(), ['consent' => 'granted', 'path' => '/', 'ip' => $request->ip(), 'ua' => $request->userAgent()], test: true);
        if (!$event) return $this->fail('Tidak dapat membuat event tes.', null, 503);
        $marketing->deliver($event->id);
        return $this->ok($event->fresh()->only(['delivery_status', 'delivery_message']));
    }

    public function retry(int $id, PlatformMarketing $marketing)
    {
        $event = PlatformMarketingEvent::findOrFail($id);
        if ($event->created_at->lt(now()->subDays(7))) return $this->fail('Event sudah melewati batas pengiriman 7 hari.', null, 422);
        $marketing->deliver($id);
        return $this->ok($event->fresh()->only(['delivery_status', 'delivery_message']));
    }

    public function overview(Request $request)
    {
        $input = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'app' => 'nullable|string|max:80']);
        $from = \Carbon\Carbon::parse($input['from'] ?? now()->subDays(29)->toDateString())->startOfDay();
        $to = \Carbon\Carbon::parse($input['to'] ?? now()->toDateString())->endOfDay();
        if ($from->diffInDays($to) > 366) return $this->fail('Pilih rentang maksimal 366 hari.', null, 422);
        $query = PlatformMarketingEvent::where('is_test', false)->whereBetween('created_at', [$from, $to]);
        if (!empty($input['app'])) $query->where('app_slug', $input['app']);
        $counts = (clone $query)->selectRaw('event_name, COUNT(*) AS total')->groupBy('event_name')->pluck('total', 'event_name');
        $purchases = (clone $query)->where('event_name', 'Purchase');
        $visitors = (clone $query)->where('event_name', 'PageView')->distinct()->count('visitor_id');
        $sessions = (clone $query)->where('event_name', 'PageView')->distinct()->count('session_id');
        $daily = (clone $query)->selectRaw("DATE(created_at) AS date, SUM(CASE WHEN event_name = 'PageView' THEN 1 ELSE 0 END) AS views, SUM(CASE WHEN event_name = 'Purchase' THEN amount ELSE 0 END) AS revenue")
            ->groupByRaw('DATE(created_at)')->orderBy('date')->get();
        $campaigns = (clone $query)->selectRaw("source, campaign, COUNT(DISTINCT CASE WHEN event_name = 'PageView' THEN session_id END) AS sessions, SUM(CASE WHEN event_name = 'CompleteRegistration' THEN 1 ELSE 0 END) AS registrations, SUM(CASE WHEN event_name = 'InitiateCheckout' THEN 1 ELSE 0 END) AS checkouts, SUM(CASE WHEN event_name = 'Purchase' THEN 1 ELSE 0 END) AS purchases, SUM(CASE WHEN event_name = 'Purchase' THEN amount ELSE 0 END) AS revenue")
            ->groupBy('source', 'campaign')->orderByDesc('revenue')->limit(100)->get();
        return $this->ok([
            'visitors' => $visitors, 'sessions' => $sessions, 'counts' => $counts, 'revenue' => (int) $purchases->sum('amount'),
            'daily' => $daily, 'campaigns' => $campaigns,
            'pages' => (clone $query)->where('event_name', 'PageView')->selectRaw('path, COUNT(*) AS views')->groupBy('path')->orderByDesc('views')->limit(10)->get(),
            'devices' => (clone $query)->where('event_name', 'PageView')->selectRaw('device, COUNT(*) AS views')->groupBy('device')->get(),
            'deliveries' => PlatformMarketingEvent::whereBetween('created_at', [$from, $to])->where('delivery_status', '!=', 'disabled')->latest()->limit(30)->get(['id', 'event_name', 'delivery_status', 'delivery_message', 'attempts', 'created_at', 'is_test']),
            'timezone' => config('app.timezone'), 'from' => $from->toDateString(), 'to' => $to->toDateString(),
        ]);
    }
}
