<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\BrandSetting;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Product;
use App\Models\LoyaltySetting;
use App\Models\PaymentSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderPageController extends Controller
{
    public function __invoke(Request $request, string $tableToken = null)
    {
        $tableToken = $tableToken ?? (string) $request->query('table', '');

        $table = null;
        if ($tableToken !== '') {
            $table = DiningTable::query()
                ->where('public_id', $tableToken)
                ->where('is_active', true)
                ->firstOrFail();
        }

        $testTables = collect();
        $demoEnabled = (bool) (BrandSetting::current()?->customer_demo_mode_enabled ?? false);
        if (!$table && $demoEnabled) {
            $testTables = DiningTable::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->limit(20)
                ->get();
        }

        // Debug
        \Log::info('OrderPageController debug', [
            'table' => $table ? $table->id : null,
            'demoEnabled' => $demoEnabled,
            'testTablesCount' => $testTables->count(),
            'testTables' => $testTables->pluck('name')->toArray(),
        ]);

        $pendingOrder = null;
        if ($table) {
            $recentCompletedCutoff = now()->subMinutes(5);

            $order = Order::query()
                ->with('items.options')
                ->where('dining_table_id', $table->id)
                ->where('status', '!=', Order::STATUS_CANCELLED)
                ->where(function ($q) use ($recentCompletedCutoff) {
                    $q->where('status', '!=', Order::STATUS_COMPLETED)
                        ->orWhere('updated_at', '>=', $recentCompletedCutoff);
                })
                ->orderByDesc('id')
                ->first();

            if ($order) {
                $pendingOrder = [
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'total_amount' => (int) $order->total_amount,
                    'created_at' => $order->created_at?->toISOString(),
                    'items' => $order->items->map(function ($it) {
                        return [
                            'product_name' => $it->product_name,
                            'qty' => (int) $it->qty,
                            'unit_price' => (int) $it->unit_price,
                            'line_total' => (int) $it->line_total,
                            'options' => $it->options->map(fn ($o) => [
                                'option_name' => $o->option_name,
                                'value_name' => $o->value_name,
                            ])->values(),
                        ];
                    })->values(),
                ];
            }
        }

        $categories = app(\App\Services\Cache\TenantCache::class)->remember('categories', 3600, function () {
            return Category::query()
                ->where('is_active', true)
                ->with(['products' => function ($q) {
                    $q->where('is_available', true)
                        ->with(['packageItems.itemProduct'])
                        ->with(['options' => function ($q2) {
                            $q2->where('is_active', true)
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->with(['values' => function ($q3) {
                                    $q3->where('is_active', true)
                                        ->orderBy('sort_order')
                                        ->orderBy('name');
                                }]);
                        }])
                        ->orderBy('sort_order')
                        ->orderBy('name');
                }])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        });

        $now = now();
        $featuredPackages = Product::query()
            ->where('is_package', true)
            ->where('show_as_banner', true)
            ->where('is_available', true)
            ->whereHas('categories', fn ($q) => $q->where('is_active', true))
            ->where(function ($q) use ($now) {
                $q->whereNull('banner_starts_at')
                    ->orWhere('banner_starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('banner_ends_at')
                    ->orWhere('banner_ends_at', '>=', $now);
            })
            ->with(['options' => function ($q2) {
                $q2->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->with(['values' => function ($q3) {
                        $q3->where('is_active', true)
                            ->orderBy('sort_order')
                            ->orderBy('name');
                    }]);
            }])
            ->with(['packageItems.itemProduct'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(10)
            ->get();

        $loyalty = LoyaltySetting::current();
        $userPointsBalance = Auth::check() ? (int) (Auth::user()?->points_balance ?? 0) : 0;

        $paymentSetting = PaymentSetting::current();
        $enabled = $paymentSetting?->enabledMethods() ?? [];
        $qrisMethods = array_values(array_filter($enabled, fn ($m) => in_array($m, ['qris_static', 'qris_dynamic'], true)));

        // Self-order only allows QRIS. Prefer dynamic if enabled, otherwise static.
        $defaultQrisMethod = in_array('qris_dynamic', $qrisMethods, true)
            ? 'qris_dynamic'
            : (in_array('qris_static', $qrisMethods, true) ? 'qris_static' : null);

        return view('customer.order', [
            'table' => $table,
            'categories' => $categories,
            'featuredPackages' => $featuredPackages,
            'testTables' => $testTables,
            'pendingOrder' => $pendingOrder,
            'loyalty' => $loyalty,
            'userPointsBalance' => $userPointsBalance,
            'qrisMethods' => $qrisMethods,
            'defaultQrisMethod' => $defaultQrisMethod,
            'brand' => BrandSetting::current(),
        ]);
    }
}
