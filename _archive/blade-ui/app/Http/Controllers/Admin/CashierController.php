<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItemOption;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Services\Payments\PaymentGateway;
use App\Services\Realtime\RealtimeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CashierController extends Controller
{
    public function menu()
    {
        $paymentSetting = PaymentSetting::current();

        $paymentMethods = [
            'cash' => 'Cash (Tunai)',
            'qris_static' => 'QRIS (Statis)',
            'qris_dynamic' => 'QRIS (Dinamis - API)',
        ];

        $enabled = $paymentSetting?->enabledMethods() ?? ['cash', 'qris_static'];
        $paymentMethods = array_intersect_key($paymentMethods, array_flip($enabled));

        $categories = Category::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $tables = DiningTable::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('code')
            ->get();

        $products = Product::query()
            ->with([
                'categories:id,name',
                'options' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name'),
                'options.values' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('admin.cashier.manage_menu', [
            'categories' => $categories,
            'tables' => $tables,
            'products' => $products,
            'paymentSetting' => $paymentSetting,
            'paymentMethods' => $paymentMethods,
        ]);
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        $slug = Str::slug($validated['name']);
        $baseSlug = $slug;
        $counter = 2;
        while (Category::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        $category = Category::query()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        return response()->json([
            'ok' => true,
            'category' => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
            ],
        ], 201);
    }

    public function updateCategory(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        $slug = Str::slug($validated['name']);
        $baseSlug = $slug;
        $counter = 2;
        while (Category::query()->where('slug', $slug)->where('id', '!=', $category->id)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        $category->update([
            'name' => $validated['name'],
            'slug' => $slug,
        ]);

        return response()->json([
            'ok' => true,
            'category' => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
            ],
        ]);
    }

    public function destroyCategory(Category $category)
    {
        if ($category->products()->count() > 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Kategori tidak dapat dihapus karena masih dipakai produk.',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    public function storeProduct(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'integer', 'min:0'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);

        $slug = Str::slug($validated['name']);
        $baseSlug = $slug;
        $counter = 2;
        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product = Product::query()->create([
            'category_id' => (int) $validated['category_id'],
            'name' => (string) $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'price' => (int) $validated['price'],
            'image_path' => $imagePath,
            'sort_order' => 0,
            'is_available' => true,
            'track_stock' => false,
            'stock' => null,
            'is_package' => false,
            'show_as_banner' => false,
        ]);

        $product->categories()->sync([(int) $validated['category_id']]);
        $product->load('categories:id,name');

        return response()->json([
            'ok' => true,
            'product' => $this->mapCashierProduct($product),
        ], 201);
    }

    public function updateProduct(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'integer', 'min:0'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);

        $slug = Str::slug($validated['name']);
        $baseSlug = $slug;
        $counter = 2;
        while (Product::query()->where('slug', $slug)->where('id', '!=', $product->id)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        $imagePath = $product->image_path;
        if ($request->hasFile('image')) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product->update([
            'category_id' => (int) $validated['category_id'],
            'name' => (string) $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'price' => (int) $validated['price'],
            'image_path' => $imagePath,
        ]);

        $product->categories()->sync([(int) $validated['category_id']]);
        $product->load('categories:id,name');

        return response()->json([
            'ok' => true,
            'product' => $this->mapCashierProduct($product),
        ]);
    }

    public function destroyProduct(Product $product)
    {
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    public function storeProductOption(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(['single', 'multi'])],
            'is_required' => ['nullable', 'boolean'],
        ]);

        $option = $product->options()->create([
            'name' => (string) $validated['name'],
            'type' => (string) $validated['type'],
            'is_required' => (bool) ($validated['is_required'] ?? false),
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return response()->json([
            'ok' => true,
            'option' => $this->mapCashierOption($option->load('values')),
        ], 201);
    }

    public function updateProductOption(Request $request, Product $product, ProductOption $option)
    {
        abort_unless((int) $option->product_id === (int) $product->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(['single', 'multi'])],
            'is_required' => ['nullable', 'boolean'],
        ]);

        $option->update([
            'name' => (string) $validated['name'],
            'type' => (string) $validated['type'],
            'is_required' => (bool) ($validated['is_required'] ?? false),
        ]);

        return response()->json([
            'ok' => true,
            'option' => $this->mapCashierOption($option->fresh()->load('values')),
        ]);
    }

    public function destroyProductOption(Product $product, ProductOption $option)
    {
        abort_unless((int) $option->product_id === (int) $product->id, 404);
        $option->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    public function storeProductOptionValue(Request $request, Product $product, ProductOption $option)
    {
        abort_unless((int) $option->product_id === (int) $product->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'price_delta' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
        ]);

        $value = $option->values()->create([
            'name' => (string) $validated['name'],
            'price_delta' => (int) ($validated['price_delta'] ?? 0),
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return response()->json([
            'ok' => true,
            'value' => [
                'id' => (int) $value->id,
                'name' => (string) $value->name,
                'price_delta' => (int) $value->price_delta,
            ],
        ], 201);
    }

    public function updateProductOptionValue(Request $request, Product $product, ProductOption $option, ProductOptionValue $value)
    {
        abort_unless((int) $option->product_id === (int) $product->id, 404);
        abort_unless((int) $value->product_option_id === (int) $option->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'price_delta' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
        ]);

        $value->update([
            'name' => (string) $validated['name'],
            'price_delta' => (int) ($validated['price_delta'] ?? 0),
        ]);

        return response()->json([
            'ok' => true,
            'value' => [
                'id' => (int) $value->id,
                'name' => (string) $value->name,
                'price_delta' => (int) $value->price_delta,
            ],
        ]);
    }

    public function destroyProductOptionValue(Product $product, ProductOption $option, ProductOptionValue $value)
    {
        abort_unless((int) $option->product_id === (int) $product->id, 404);
        abort_unless((int) $value->product_option_id === (int) $option->id, 404);

        $value->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    private function mapCashierOption(ProductOption $option): array
    {
        return [
            'id' => (int) $option->id,
            'name' => (string) $option->name,
            'type' => (string) $option->type,
            'is_required' => (bool) $option->is_required,
            'values' => ($option->values ?? collect())->map(fn ($value) => [
                'id' => (int) $value->id,
                'name' => (string) $value->name,
                'price_delta' => (int) $value->price_delta,
            ])->values()->all(),
        ];
    }

    private function mapCashierProduct(Product $product): array
    {
        $categoryIds = $product->categories?->pluck('id')->map(fn ($value) => (int) $value)->values()->all();
        if (empty($categoryIds) && $product->category_id) {
            $categoryIds = [(int) $product->category_id];
        }

        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'price' => (int) $product->price,
            'description' => (string) ($product->description ?? ''),
            'image_url' => $product->imageUrl(),
            'track_stock' => (bool) ($product->track_stock ?? false),
            'stock' => $product->stock === null ? null : (int) $product->stock,
            'category_ids' => $categoryIds,
            'options' => $product->options()
                ->with(['values' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name')])
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn ($option) => $this->mapCashierOption($option))
                ->values()
                ->all(),
        ];
    }

    public function index(Request $request)
    {
        $paymentSetting = PaymentSetting::current();

        $paymentMethods = [
            'cash' => 'Cash (Tunai)',
            'qris_static' => 'QRIS (Statis)',
            'qris_dynamic' => 'QRIS (Dinamis - API)',
        ];

        $enabled = $paymentSetting?->enabledMethods() ?? ['cash', 'qris_static'];
        $paymentMethods = array_intersect_key($paymentMethods, array_flip($enabled));

        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $tables = DiningTable::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('code')
            ->get();

        $products = Product::query()
            ->with([
                'categories:id,name',
                'options' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('name'),
                'options.values' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('name'),
            ])
            ->where('is_available', true)
            ->where(function ($q) {
                $q->whereHas('categories', fn ($qq) => $qq->where('is_active', true))
                    ->orWhereHas('category', fn ($qq) => $qq->where('is_active', true));
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // Get orders count for notification badge
        $ordersCount = Order::query()
            ->whereNotIn('status', [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED])
            ->count();

        return view('admin.cashier.index', [
            'categories' => $categories,
            'tables' => $tables,
            'products' => $products,
            'paymentSetting' => $paymentSetting,
            'paymentMethods' => $paymentMethods,
            'ordersCount' => $ordersCount,
            'realtimePublicUrl' => (string) config('realtime.public_url'),
            'pollUrl' => route('admin.orders.poll'),
        ]);
    }

    public function ordersCount(Request $request)
    {
        $ordersCount = Order::query()
            ->whereNotIn('status', [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED])
            ->count();

        return response()->json(['ordersCount' => $ordersCount]);
    }

    public function orders(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                'all',
                Order::STATUS_NEW,
                Order::STATUS_ACCEPTED,
                Order::STATUS_PREPARING,
                Order::STATUS_COMPLETED,
                Order::STATUS_CANCELLED,
            ])],
            'order' => ['nullable', 'string', 'max:64'],
        ]);

        // Cashier order list should show everything by default (paid orders can be completed immediately).
        $status = (string) ($validated['status'] ?? 'all');
        $selectedOrderNumber = (string) ($validated['order'] ?? '');

        $query = Order::query()
            ->with('items.options')
            ->orderByDesc('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $orders = $query->limit(200)->get();

        $selected = null;
        if ($selectedOrderNumber !== '') {
            $selected = $orders->firstWhere('order_number', $selectedOrderNumber);
            if (!$selected) {
                $selected = Order::query()
                    ->with('items.options')
                    ->where('order_number', $selectedOrderNumber)
                    ->first();

                if ($selected) {
                    $orders->prepend($selected);
                }
            }
        }

        $ordersPayload = $orders->map(function (Order $o) {
            return [
                'order_number' => (string) $o->order_number,
                'status' => (string) $o->status,
                'payment_status' => (string) $o->payment_status,
                'payment_method' => (string) ($o->payment_method ?? ''),
            'service_type' => (string) ($o->service_type ?? 'dine_in'),
            'order_source' => (string) ($o->order_source ?? ''),
                'table_label' => (string) ($o->table_label ?? ''),
                'customer_name' => (string) ($o->customer_name ?? ''),
                'notes' => (string) ($o->notes ?? ''),
                'total_amount' => (int) ($o->total_amount ?? 0),
                'created_at' => $o->created_at?->toISOString(),
                'payment_qr_url' => $o->payment_qr_url,
                'items' => ($o->items ?? collect())->map(function ($it) {
                    return [
                        'product_name' => (string) ($it->product_name ?? ''),
                        'qty' => (int) ($it->qty ?? 0),
                        'unit_price' => (int) ($it->unit_price ?? 0),
                        'line_total' => (int) ($it->line_total ?? 0),
                        'options' => ($it->options ?? collect())->map(fn ($op) => [
                            'option_name' => (string) ($op->option_name ?? ''),
                            'value_name' => (string) ($op->value_name ?? ''),
                            'price_delta' => (int) ($op->price_delta ?? 0),
                        ])->values()->all(),
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        $paymentSetting = PaymentSetting::current();

        return view('admin.cashier.orders', [
            'ordersPayload' => $ordersPayload,
            'selectedOrderNumber' => $selected?->order_number ?: ($selectedOrderNumber ?: null),
            'initialStatus' => $status,
            'realtimePublicUrl' => (string) config('realtime.public_url'),
            'pollUrl' => route('admin.orders.poll'),
            'cashierSettings' => [
                'auto_complete_when_paid' => (bool) ($paymentSetting?->auto_complete_when_paid ?? true),
            ],
        ]);
    }

    public function bulk(Request $request, RealtimeClient $realtime)
    {
        $paymentSetting = PaymentSetting::current();

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['complete', 'cancel', 'delete'])],
            'order_numbers' => ['required', 'array', 'min:1', 'max:200'],
            'order_numbers.*' => ['required', 'string', 'max:64'],
        ]);

        $action = (string) $validated['action'];
        $orderNumbers = array_values(array_unique(array_map('strval', $validated['order_numbers'])));

        $orders = Order::query()
            ->with('items.options')
            ->whereIn('order_number', $orderNumbers)
            ->get()
            ->keyBy('order_number');

        $updated = [];
        $failed = [];

        foreach ($orderNumbers as $orderNumber) {
            /** @var \App\Models\Order|null $order */
            $order = $orders->get($orderNumber);
            if (!$order) {
                $failed[] = ['order_number' => $orderNumber, 'reason' => 'Order tidak ditemukan.'];
                continue;
            }

            if ($action === 'complete') {
                $requiresPaidBeforeComplete =
                    (bool) ($paymentSetting?->require_paid_before_complete ?? false) ||
                    in_array((string) ($order->order_source ?? ''), ['self_order', 'public_customer', 'qr_scan'], true);

                if ($requiresPaidBeforeComplete && (string) ($order->payment_status ?? '') !== 'paid') {
                    $failed[] = ['order_number' => $orderNumber, 'reason' => 'Tidak bisa Complete: pembayaran masih Unpaid.'];
                    continue;
                }
                $order->status = Order::STATUS_COMPLETED;
                $order->save();
                $order->load('items.options');
                $realtime->emit('order.updated', $order->toArray(), $order->tenant_id);
                $updated[] = $orderNumber;
                continue;
            }

            if ($action === 'cancel') {
                $order->status = Order::STATUS_CANCELLED;
                $order->save();
                $order->load('items.options');
                $realtime->emit('order.updated', $order->toArray(), $order->tenant_id);
                $updated[] = $orderNumber;
                continue;
            }

            if ($action === 'delete') {
                if ((string) $order->status !== Order::STATUS_CANCELLED) {
                    $failed[] = ['order_number' => $orderNumber, 'reason' => 'Hanya pesanan dibatalkan yang bisa dihapus.'];
                    continue;
                }

                $order->delete();
                $updated[] = $orderNumber;
                continue;
            }

        }

        return response()->json([
            'ok' => true,
            'action' => $action,
            'updated' => $updated,
            'failed' => $failed,
        ]);
    }

    public function receipt(Request $request)
    {
        $orderNumber = $request->route('orderNumber');
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        $order->load('items.options');

        return view('admin.cashier.receipt', [
            'order' => $order,
            'autoprint' => $request->boolean('autoprint', true),
        ]);
    }

    public function checkout(Request $request, RealtimeClient $realtime, PaymentGateway $payments)
    {
        $paymentSetting = PaymentSetting::current();
        if (!$paymentSetting) {
            abort(500, 'Payment settings not initialized. Run migrations.');
        }

        $validated = $request->validate([
            'table' => ['nullable', 'string', 'max:32'],
            'table_label' => ['nullable', 'string', 'max:32'],
            'items' => ['required', 'string'],
            'service_type' => ['required', 'string', Rule::in(['dine_in', 'takeout'])],
            'customer_name' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['required', 'string', Rule::in(['cash', 'qris', 'qris_static', 'qris_dynamic'])],
            'payment_status' => ['required', 'string', Rule::in(['paid', 'unpaid'])],
        ]);

        $paymentMethod = (string) $validated['payment_method'];
        if ($paymentMethod === 'qris') {
            $paymentMethod = 'qris_static';
        }

        $enabled = $paymentSetting->enabledMethods();
        if (!in_array($paymentMethod, $enabled, true)) {
            return back()->withErrors(['payment_method' => 'Metode pembayaran tidak aktif. Silakan atur di Payment Settings.'])->withInput();
        }

        $paymentStatus = (string) $validated['payment_status'];
        if ($paymentMethod === 'qris_dynamic') {
            // Dynamic QR should be created unpaid by default.
            $paymentStatus = 'unpaid';
        }

        if (
            ($paymentSetting->require_paid_before_submit ?? false) &&
            $paymentMethod !== 'qris_dynamic' &&
            $paymentStatus !== 'paid'
        ) {
            return back()->withErrors(['payment_status' => 'Mode kasir mewajibkan status pembayaran Paid sebelum memproses order.'])->withInput();
        }

        $serviceType = (string) $validated['service_type'];

        $table = null;
        $tableLabel = null;

        if ($serviceType === 'takeout') {
            $tableLabel = 'Takeout';
        } else {
            $tablePublicId = trim((string) ($validated['table'] ?? ''));
            if ($tablePublicId !== '') {
                $table = DiningTable::query()
                    ->where('public_id', $tablePublicId)
                    ->where('is_active', true)
                    ->firstOrFail();

                $tableLabel = $table->name ?: $table->code;
            } else {
                $tableLabel = trim((string) ($validated['table_label'] ?? ''));
                $tableLabel = $tableLabel !== '' ? $tableLabel : 'Walk-in';
            }
        }

        $itemsRaw = json_decode($validated['items'], true);
        if (!is_array($itemsRaw) || count($itemsRaw) < 1) {
            return back()->withErrors(['items' => 'Keranjang kosong.'])->withInput();
        }

        // Normalize items by (product + selected options). Never trust client pricing.
        $variants = [];
        foreach ($itemsRaw as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $qty = (int) ($row['qty'] ?? 0);
            $optionsInput = $row['options'] ?? [];

            if ($productId <= 0 || $qty <= 0 || $qty > 99) {
                continue;
            }

            $canonical = $this->canonicalizeOptions($optionsInput);
            $signature = json_encode($canonical);
            if ($signature === false) {
                continue;
            }
            $variantKey = $productId.'|'.$signature;

            if (!isset($variants[$variantKey])) {
                $variants[$variantKey] = [
                    'product_id' => $productId,
                    'qty' => 0,
                    'options' => $canonical,
                ];
            }
            $variants[$variantKey]['qty'] += $qty;
        }

        if (count($variants) < 1) {
            return back()->withErrors(['items' => 'Keranjang kosong.'])->withInput();
        }

        foreach ($variants as $v) {
            if ($v['qty'] < 1 || $v['qty'] > 99) {
                return back()->withErrors(['items' => 'Qty tidak valid.'])->withInput();
            }
        }

        $productIds = array_values(array_unique(array_map(fn ($v) => (int) $v['product_id'], $variants)));

        $products = Product::query()
            ->with([
                'options' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'options.values' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
            ])
            ->whereIn('id', $productIds)
            ->where('is_available', true)
            ->where(function ($q) {
                $q->whereHas('categories', fn ($qq) => $qq->where('is_active', true))
                    ->orWhereHas('category', fn ($qq) => $qq->where('is_active', true));
            })
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($productIds)) {
            return back()->withErrors(['items' => 'Ada menu yang tidak tersedia.'])->withInput();
        }

        // Validate options against product definitions (server-side).
        foreach ($variants as $v) {
            $product = $products[$v['product_id']] ?? null;
            if (!$product) {
                return back()->withErrors(['items' => 'Ada menu yang tidak tersedia.'])->withInput();
            }
            $error = $this->validateSelectedOptionsForProduct($product, $v['options']);
            if ($error) {
                return back()->withErrors(['items' => $error])->withInput();
            }
        }

        // Compute needed qty per product.
        $qtyByProductId = [];
        foreach ($variants as $v) {
            $pid = (int) $v['product_id'];
            $qtyByProductId[$pid] = ($qtyByProductId[$pid] ?? 0) + (int) $v['qty'];
        }

        try {
            $order = DB::transaction(function () use ($validated, $table, $tableLabel, $variants, $products, $qtyByProductId, $paymentMethod, $paymentStatus, $paymentSetting) {
                // Lock products to prevent overselling stock
                $locked = Product::query()
                    ->whereIn('id', array_keys($qtyByProductId))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($qtyByProductId as $pid => $needQty) {
                    /** @var \App\Models\Product|null $p */
                    $p = $locked->get($pid);
                    if (!$p) {
                        throw new \RuntimeException('Ada menu yang tidak tersedia.');
                    }

                    if ($p->track_stock) {
                        $available = (int) ($p->stock ?? 0);
                        if ($available < (int) $needQty) {
                            throw new \RuntimeException('Stok tidak cukup untuk: '.$p->name.' (tersisa '.$available.').');
                        }
                    }
                }

                // Decrement stock after validation
                foreach ($qtyByProductId as $pid => $needQty) {
                    /** @var \App\Models\Product $p */
                    $p = $locked->get($pid);
                    if ($p && $p->track_stock) {
                        $p->stock = (int) ($p->stock ?? 0) - (int) $needQty;
                        $p->save();
                    }
                }

                $orderNumber = 'ORD-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(4));

                /** @var \App\Models\User|null $user */
                $user = Auth::user();

                $status = Order::STATUS_NEW;
                if ($paymentStatus === 'paid') {
                    // Set status based on payment method
                    if ($paymentMethod === 'qris_static') {
                        $status = Order::STATUS_NEW; // Require manual confirmation
                    } elseif ($paymentMethod === 'qris_dynamic' || $paymentMethod === 'cash') {
                        $status = Order::STATUS_ACCEPTED;
                    } elseif ($paymentSetting?->auto_complete_when_paid) {
                        $status = Order::STATUS_COMPLETED;
                    } else {
                        $status = Order::STATUS_ACCEPTED;
                    }
                }

                $order = Order::query()->create([
                    'order_number' => $orderNumber,
                    'dining_table_id' => $table?->id,
                    'table_label' => $tableLabel,
                    'user_id' => $user?->id,
                    'customer_name' => $validated['customer_name'] ?: null,
                    'service_type' => (string) ($validated['service_type'] ?? 'dine_in'),
                    'order_source' => 'cashier',
                    'status' => $status,
                    'total_amount' => 0,
                    'discount_amount' => 0,
                    'redeemed_points' => 0,
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'notes' => $validated['notes'] ?: null,
                ]);

                $totalAmount = 0;

                foreach ($variants as $v) {
                    /** @var \App\Models\Product $product */
                    $product = $products[$v['product_id']];
                    $qty = (int) $v['qty'];

                    $baseUnitPrice = (int) $product->price;
                    $optionsTotal = $this->computeOptionsTotal($product, $v['options']);
                    $unitPrice = $baseUnitPrice + $optionsTotal;
                    $lineTotal = $unitPrice * $qty;
                    $totalAmount += $lineTotal;

                    /** @var \App\Models\OrderItem $orderItem */
                    $orderItem = $order->items()->create([
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'unit_price' => $unitPrice,
                        'base_unit_price' => $baseUnitPrice,
                        'options_total' => $optionsTotal,
                        'qty' => $qty,
                        'line_total' => $lineTotal,
                    ]);

                    $snapRows = $this->buildOptionSnapshotRows($orderItem->id, $product, $v['options']);
                    if (count($snapRows) > 0) {
                        OrderItemOption::query()->insert($snapRows);
                    }
                }

                $order->total_amount = max(0, (int) $totalAmount);
                $order->save();

                return $order;
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['items' => $e->getMessage()])->withInput();
        }

        // Create dynamic QRIS after order persisted
        if ($paymentMethod === 'qris_dynamic') {
            try {
                $charge = $payments->createDynamicQris($order, $paymentSetting);

                $order->payment_ref = (string) ($charge['reference'] ?? $order->payment_ref);
                $order->payment_provider = (string) ($paymentSetting->dynamic_provider ?? '');
                $order->payment_qr_url = $charge['qr_url'] ?? null;
                $order->payment_qr_string = $charge['qr_string'] ?? null;
                $order->payment_meta = $charge['meta'] ?? null;
                $order->save();
            } catch (\RuntimeException $e) {
                return redirect()
                    ->route('admin.orders.show', $order)
                    ->with('status', 'Order dibuat, tapi QRIS dinamis gagal dibuat: '.$e->getMessage());
            }
        }

        $order->load('items.options');

        // Ensure cashier-created unpaid orders are treated as NEW and not auto-completed
        if (isset($paymentStatus) && $paymentStatus === 'unpaid') {
            $order->status = Order::STATUS_NEW;
            $order->save();
        }

        $realtime->emit('order.created', $order->toArray(), $order->tenant_id);

        // Redirect to Orders list and open the appropriate tab (Pesanan Baru for unpaid, All for paid)
        $tab = ($paymentStatus === 'unpaid') ? 'new' : 'all';

        return redirect()
            ->route('admin.cashier.orders', ['status' => $tab, 'order' => $order->order_number])
            ->with('status', 'Order kasir dibuat: '.$order->order_number);
    }

    private function canonicalizeOptions(mixed $optionsInput): array
    {
        if (!is_array($optionsInput)) {
            return [];
        }

        $byOption = [];

        foreach ($optionsInput as $opt) {
            if (!is_array($opt)) {
                continue;
            }
            $optionId = (int) ($opt['option_id'] ?? 0);
            if ($optionId <= 0) {
                continue;
            }

            $valueIds = [];
            if (isset($opt['value_id'])) {
                $v = (int) $opt['value_id'];
                if ($v > 0) {
                    $valueIds = [$v];
                }
            } elseif (isset($opt['value_ids']) && is_array($opt['value_ids'])) {
                $valueIds = array_values(array_unique(array_map(fn ($x) => (int) $x, $opt['value_ids'])));
                $valueIds = array_values(array_filter($valueIds, fn ($x) => $x > 0));
            }

            sort($valueIds);
            $byOption[$optionId] = $valueIds;
        }

        ksort($byOption);

        $out = [];
        foreach ($byOption as $optionId => $valueIds) {
            $out[] = [
                'option_id' => $optionId,
                'value_ids' => $valueIds,
            ];
        }

        return $out;
    }

    private function validateSelectedOptionsForProduct(Product $product, array $canonicalOptions): ?string
    {
        $selectedMap = [];
        foreach ($canonicalOptions as $row) {
            $optionId = (int) ($row['option_id'] ?? 0);
            if ($optionId <= 0) {
                continue;
            }
            $valueIds = $row['value_ids'] ?? [];
            $selectedMap[$optionId] = is_array($valueIds) ? $valueIds : [];
        }

        $productOptions = $product->options->keyBy('id');

        // No unknown options
        foreach (array_keys($selectedMap) as $optionId) {
            if (!$productOptions->has($optionId)) {
                return 'Pilihan add-on tidak valid.';
            }
        }

        foreach ($productOptions as $opt) {
            $valueIds = $selectedMap[$opt->id] ?? [];
            $valueIds = array_values(array_unique(array_map(fn ($x) => (int) $x, is_array($valueIds) ? $valueIds : [])));
            $valueIds = array_values(array_filter($valueIds, fn ($x) => $x > 0));

            if ($opt->is_required) {
                if ($opt->type === \App\Models\ProductOption::TYPE_MULTI) {
                    if (count($valueIds) < 1) {
                        return 'Wajib pilih: '.$opt->name;
                    }
                } else {
                    if (count($valueIds) !== 1) {
                        return 'Wajib pilih 1: '.$opt->name;
                    }
                }
            }

            if ($opt->type === \App\Models\ProductOption::TYPE_SINGLE && count($valueIds) > 1) {
                return 'Pilihan tidak valid: '.$opt->name;
            }

            if (count($valueIds) > 0) {
                $validValues = $opt->values->keyBy('id');
                foreach ($valueIds as $valueId) {
                    if (!$validValues->has($valueId)) {
                        return 'Pilihan tidak valid: '.$opt->name;
                    }
                }
            }
        }

        return null;
    }

    private function computeOptionsTotal(Product $product, array $canonicalOptions): int
    {
        $selectedMap = [];
        foreach ($canonicalOptions as $row) {
            $optionId = (int) ($row['option_id'] ?? 0);
            if ($optionId <= 0) {
                continue;
            }
            $valueIds = $row['value_ids'] ?? [];
            $selectedMap[$optionId] = is_array($valueIds) ? $valueIds : [];
        }

        $total = 0;
        foreach ($product->options as $opt) {
            $valueIds = $selectedMap[$opt->id] ?? [];
            $valueIds = array_values(array_unique(array_map(fn ($x) => (int) $x, is_array($valueIds) ? $valueIds : [])));
            $valueIds = array_values(array_filter($valueIds, fn ($x) => $x > 0));

            if (count($valueIds) < 1) {
                continue;
            }

            $values = $opt->values->keyBy('id');
            foreach ($valueIds as $valueId) {
                $v = $values->get($valueId);
                if ($v) {
                    $total += (int) $v->price_delta;
                }
            }
        }

        return $total;
    }

    private function buildOptionSnapshotRows(int $orderItemId, Product $product, array $canonicalOptions): array
    {
        $selectedMap = [];
        foreach ($canonicalOptions as $row) {
            $optionId = (int) ($row['option_id'] ?? 0);
            if ($optionId <= 0) {
                continue;
            }
            $valueIds = $row['value_ids'] ?? [];
            $selectedMap[$optionId] = is_array($valueIds) ? $valueIds : [];
        }

        $rows = [];
        $now = now();

        foreach ($product->options as $opt) {
            $valueIds = $selectedMap[$opt->id] ?? [];
            $valueIds = array_values(array_unique(array_map(fn ($x) => (int) $x, is_array($valueIds) ? $valueIds : [])));
            $valueIds = array_values(array_filter($valueIds, fn ($x) => $x > 0));
            if (count($valueIds) < 1) {
                continue;
            }

            $values = $opt->values->keyBy('id');
            foreach ($valueIds as $valueId) {
                $v = $values->get($valueId);
                if (!$v) {
                    continue;
                }
                $rows[] = [
                    'order_item_id' => $orderItemId,
                    'product_option_id' => $opt->id,
                    'product_option_value_id' => $v->id,
                    'option_name' => $opt->name,
                    'value_name' => $v->name,
                    'price_delta' => (int) $v->price_delta,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows;
    }
}
