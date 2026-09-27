<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\Product;
use App\Models\LoyaltySetting;
use App\Models\PointTransaction;
use App\Models\PaymentSetting;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Realtime\RealtimeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function store(Request $request, RealtimeClient $realtime, PaymentGateway $payments)
    {
        $paymentSetting = PaymentSetting::current();
        if (!$paymentSetting) {
            abort(500, 'Payment settings not initialized. Run migrations.');
        }

        $validated = $request->validate([
            'table' => ['required', 'string', 'max:32'],
            'items' => ['required', 'string'],
            'customer_name' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
            'redeem_points' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            // Self-order only supports QRIS; UI sends qris_static/qris_dynamic.
            'payment_method' => ['required', 'string'],
        ]);

        $paymentMethod = (string) ($validated['payment_method'] ?? '');
        if ($paymentMethod === 'qris') {
            $paymentMethod = 'qris_static';
        }

        if (!in_array($paymentMethod, ['qris_static', 'qris_dynamic'], true)) {
            return back()->withErrors(['payment_method' => 'Self order hanya mendukung QRIS.'])->withInput();
        }

        $enabled = $paymentSetting->enabledMethods();
        if (!in_array($paymentMethod, $enabled, true)) {
            return back()->withErrors(['payment_method' => 'Metode pembayaran tidak aktif. Silakan atur di Payment Settings.'])->withInput();
        }

        $table = DiningTable::query()
            ->where('public_id', $validated['table'])
            ->where('is_active', true)
            ->firstOrFail();

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
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
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

        // Stock check is enforced inside the transaction (lockForUpdate), but we can
        // compute needed qty per product here.
        $qtyByProductId = [];
        foreach ($variants as $v) {
            $pid = (int) $v['product_id'];
            $qtyByProductId[$pid] = ($qtyByProductId[$pid] ?? 0) + (int) $v['qty'];
        }

        $redeemRequested = (int) ($validated['redeem_points'] ?? 0);

        try {
            $order = DB::transaction(function () use ($validated, $table, $variants, $products, $qtyByProductId, $redeemRequested, $paymentMethod) {
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

                $order = Order::query()->create([
                    'order_number' => $orderNumber,
                    'dining_table_id' => $table->id,
                    'table_label' => $table->name ?: $table->code,
                    'user_id' => $user?->id,
                    'customer_name' => $validated['customer_name'] ?: null,
                    'service_type' => 'dine_in',
                    'order_source' => 'self_order',
                    'status' => Order::STATUS_NEW,
                    'total_amount' => 0,
                    'discount_amount' => 0,
                    'redeemed_points' => 0,
                    'payment_method' => $paymentMethod,
                    'payment_status' => 'unpaid',
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

                // Redeem points to discount (optional)
                $discountAmount = 0;
                $redeemedPoints = 0;
                if ($redeemRequested > 0 && $user) {
                    $setting = LoyaltySetting::current();
                    if ($setting && $setting->redeem_enabled) {
                        $rpPerPoint = (int) ($setting->redeem_rp_per_point ?? 0);
                        $minSpendToRedeem = (int) ($setting->redeem_min_spend_amount ?? 0);
                        if ($rpPerPoint > 0 && $totalAmount >= $minSpendToRedeem) {
                            /** @var User $lockedUser */
                            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
                            $availablePoints = (int) ($lockedUser->points_balance ?? 0);

                            $usePoints = min($redeemRequested, $availablePoints);

                            $maxPoints = $setting->redeem_max_points_per_order;
                            if (is_int($maxPoints) && $maxPoints > 0) {
                                $usePoints = min($usePoints, $maxPoints);
                            }

                            // Don't allow discount more than the order total.
                            $maxByTotal = (int) floor($totalAmount / $rpPerPoint);
                            $usePoints = min($usePoints, $maxByTotal);

                            $discountAmount = $usePoints * $rpPerPoint;
                            $maxDiscount = $setting->redeem_max_discount_rp;
                            if (is_int($maxDiscount) && $maxDiscount > 0) {
                                $discountAmount = min($discountAmount, $maxDiscount);
                                $usePoints = (int) floor($discountAmount / $rpPerPoint);
                                $discountAmount = $usePoints * $rpPerPoint;
                            }

                            if ($usePoints > 0 && $discountAmount > 0) {
                                $redeemedPoints = $usePoints;

                                // Prevent double redeem for the same order.
                                $exists = PointTransaction::query()
                                    ->where('user_id', $lockedUser->id)
                                    ->where('source_type', 'order_redeem')
                                    ->where('source_id', $order->id)
                                    ->exists();

                                if (!$exists) {
                                    PointTransaction::query()->create([
                                        'user_id' => $lockedUser->id,
                                        'source_type' => 'order_redeem',
                                        'source_id' => $order->id,
                                        'points' => -$redeemedPoints,
                                        'note' => 'Pakai poin untuk order '.$order->order_number,
                                    ]);

                                    $lockedUser->points_balance = max(0, $availablePoints - $redeemedPoints);
                                    $lockedUser->save();
                                }
                            }
                        }
                    }
                }

                $order->discount_amount = (int) $discountAmount;
                $order->redeemed_points = (int) $redeemedPoints;
                $order->total_amount = max(0, (int) $totalAmount - (int) $discountAmount);
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
                    ->route('customer.order.success', ['orderNumber' => $order->order_number])
                    ->with('status', 'Order dibuat, tapi QRIS dinamis gagal dibuat: '.$e->getMessage());
            }
        }

        $order->load('items.options');
        $realtime->emit('order.created', $order->toArray(), $order->tenant_id);

        return redirect()->route('customer.order.success', ['orderNumber' => $order->order_number]);
    }

    public function thanks(string $orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)->firstOrFail();
        
        return view('customer.thanks', [
            'order' => $order->load('items.options'),
            'realtimePublicUrl' => (string) config('realtime.public_url'),
            'paymentSetting' => PaymentSetting::current(),
        ]);
    }

    public function status(string $orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)->firstOrFail();
        
        return response()->json([
            'order' => [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'updated_at' => $order->updated_at?->toISOString(),
            ]
        ]);
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
