<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\BrandSetting;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Organization;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentSetting;
use App\Models\PosPaymentSetting;
use App\Models\PosLoyaltySetting;
use App\Models\Product;
use App\Models\ReservationSpace;
use App\Models\SitePromotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class CustomerOrderController extends BaseApiController
{
    public function getMenu(string $tableToken): JsonResponse
    {
        $table = $this->resolveActiveTableByToken($tableToken);

        if (!$table) {
            return $this->fail('Table not found or inactive', [], 404);
        }

        return $this->menuResponse($table, $tableToken);
    }

    public function getOrganizationMenu(string $organizationSlug): JsonResponse
    {
        $organization = Organization::query()
            ->where('slug', $organizationSlug)
            ->first();

        if (!$organization) {
            return $this->fail('Organization not found', [], 404);
        }

        $tenantId = (string) ($organization->pos_tenant_slug ?: $organization->slug);
        $table = $this->resolvePublicEntryTable($tenantId);

        if (!$table) {
            return $this->fail('Belum ada meja aktif untuk halaman customer publik organisasi ini.', [], 404);
        }

        return $this->menuResponse($table, (string) $table->public_id, true);
    }

    public function createOrder(Request $request): JsonResponse
    {
        // For public customer orders, never require payment confirmation upfront
        // Customer selects payment method but payment happens later at cashier
        $requirePayment = false;

        $rules = [
            'table_token' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:1000',
            'payment_method' => ['nullable', 'string', Rule::in(['cash', 'transfer', 'gopay', 'dana', 'qris', 'qris_static'])],
        ];

        if ($requirePayment) {
            $rules['payment_confirmed'] = ['required', 'boolean'];
        } else {
            $rules['payment_confirmed'] = ['nullable', 'boolean'];
        }

        $validated = $request->validate($rules);

        if ($requirePayment && empty($validated['payment_confirmed'])) {
            return $this->fail('Pembayaran harus dikonfirmasi sebelum pesanan dikirim.', [], 422);
        }



        // Find table by public_id
        $table = DiningTable::where('public_id', $validated['table_token'])
            ->where('is_active', true)
            ->first();

        if (!$table) {
            return $this->fail('Table not found or inactive', [], 404);
        }

        $tenantId = $table->tenant_id;

        // Get POS payment settings for this tenant
        $posPaymentSetting = \App\Models\PosPaymentSetting::where('tenant_id', $tenantId)->first();

        // Validate payment method if provided
        $paymentMethod = $validated['payment_method'] ?? 'cash';
        if ($paymentMethod === 'qris_static') {
            if (!$posPaymentSetting || !$posPaymentSetting->qris_enabled) {
                return $this->fail("Metode pembayaran qris tidak tersedia.", [], 422);
            }
        } elseif ($paymentMethod !== 'cash' && (!$posPaymentSetting || !$posPaymentSetting->{$paymentMethod . '_enabled'})) {
            return $this->fail("Metode pembayaran {$paymentMethod} tidak tersedia.", [], 422);
        }

        // Validate products belong to same tenant and are available
        $productIds = collect($validated['items'])->pluck('product_id')->unique();
        DB::beginTransaction();
        try {
            $products = Product::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                return $this->fail('Beberapa produk tidak ditemukan.', [], 400);
            }

            // Calculate totals
            $totalAmount = 0;
            $orderItems = [];

            foreach ($validated['items'] as $item) {
                $product = $products[$item['product_id']];
                if (!$product->is_available) {
                    return $this->fail("Produk tidak tersedia: {$product->name}", [], 400);
                }

                if ($product->track_stock) {
                    $available = (int) ($product->stock ?? 0);
                    if ($available <= 0) {
                        return $this->fail("Stok habis untuk: {$product->name}", [], 400);
                    }
                    if ($item['quantity'] > $available) {
                        return $this->fail("Stok tidak cukup untuk: {$product->name}", [], 400);
                    }
                }

                $lineTotal = $product->price * $item['quantity'];
                $totalAmount += $lineTotal;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $product->price,
                    'base_unit_price' => $product->price,
                    'options_total' => 0,
                    'qty' => $item['quantity'],
                    'line_total' => $lineTotal,
                ];
            }

            // Create order
            $order = Order::create([
                'tenant_id' => $tenantId,
                'dining_table_id' => $table->id,
                'table_label' => $table->name ?: $table->code,
                'customer_name' => $validated['customer_name'] ?? null,
                'service_type' => 'dine_in',
                'order_source' => 'public_customer',
                'status' => Order::STATUS_NEW,
                'payment_method' => $paymentMethod,
                'payment_status' => 'unpaid',
                'total_amount' => $totalAmount,
                'discount_amount' => 0,
                'final_amount' => $totalAmount,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Create order items
            foreach ($orderItems as $itemData) {
                $itemData['order_id'] = $order->id;
                OrderItem::create($itemData);
            }

            // Decrement stock for tracked products
            foreach ($validated['items'] as $item) {
                $product = $products[$item['product_id']];
                if ($product->track_stock) {
                    $product->stock = (int) ($product->stock ?? 0) - (int) $item['quantity'];
                    $product->save();
                }
            }

            $order->load('items');

            DB::commit();

            return $this->ok([
                'order' => $this->transformOrder($order),
            ], 'Order created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Customer self-order create failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'table_token' => $validated['table_token'] ?? null,
                'tenant_id' => $tenantId ?? null,
                'request' => $request->all(),
            ]);
            return $this->fail('Failed to create order', [], 500);
        }
    }

    public function getOrderStatus(string $orderNumber): JsonResponse
    {
        $order = Order::withoutGlobalScope('tenant')
            ->with(['items', 'table'])
            ->where('order_number', $orderNumber)
            ->first();

        if (!$order) {
            return $this->fail('Order not found', [], 404);
        }

        return $this->ok([
            'order' => $this->transformOrder($order),
        ], 'Order retrieved successfully');
    }

    private function transformOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'customer_name' => $order->customer_name,
            'table' => $order->relationLoaded('table') && $order->table
                ? [
                    'id' => $order->table->id,
                    'code' => $order->table->code,
                    'name' => $order->table->name,
                ]
                : null,
            'table_label' => $order->table_label,
            'service_type' => $order->service_type,
            'order_source' => $order->order_source,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'notes' => $order->notes,
            'total_amount' => $order->total_amount,
            'final_amount' => $order->final_amount ?? $order->total_amount,
            'created_at' => optional($order->created_at)?->toIso8601String(),
            'updated_at' => optional($order->updated_at)?->toIso8601String(),
            'items' => $order->items->map(function (OrderItem $item) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity' => $item->qty,
                    'price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'selected_options' => $item->selected_options,
                ];
            })->values()->all(),
        ];
    }

    private function menuResponse(DiningTable $table, string $tableToken, bool $preferOrganizationRoot = false): JsonResponse
    {
        $tenantId = (string) $table->tenant_id;

        $products = Product::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->with('category')
            ->orderBy('category_id')
            ->orderBy('sort_order')
            ->get();

        $categories = Category::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $categoriesWithProducts = $categories->map(function ($category) use ($products) {
            $categoryProducts = $products->filter(function ($product) use ($category) {
                return $product->category_id === $category->id;
            });

            return [
                'id' => $category->id,
                'name' => $category->name,
                'products' => $categoryProducts->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'description' => $product->description,
                        'price' => $product->price,
                        'image_path' => $product->image_path,
                        'is_available' => $product->is_available,
                        'track_stock' => (bool) ($product->track_stock ?? false),
                        'stock' => $product->stock === null ? null : (int) $product->stock,
                        'is_available_now' => $product->isAvailableNow(),
                        'category' => [
                            'id' => $product->category_id,
                            'name' => optional($product->category)->name,
                        ],
                    ];
                })->values(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'table' => [
                    'id' => $table->id,
                    'public_id' => $table->public_id,
                    'code' => $table->code,
                    'name' => $table->name,
                    'tenant_slug' => $tenantId,
                    'organization_slug' => $this->resolveOrganizationSlug($tenantId),
                ],
                'categories' => $categoriesWithProducts,
                'experience' => $this->buildCustomerExperience($table, $tableToken, $preferOrganizationRoot),
            ],
            'message' => 'Menu retrieved successfully',
        ]);
    }

    private function buildCustomerExperience(DiningTable $table, string $tableToken, bool $preferOrganizationRoot = false): array
    {
        $tenantId = $table->tenant_id;
        $organization = $this->resolveOrganization($tenantId);
        $brand = BrandSetting::current();
        $recentCompletedCutoff = now()->subMinutes(5);

        $pendingOrder = Order::withoutGlobalScope('tenant')
            ->with(['items', 'table'])
            ->where('tenant_id', $tenantId)
            ->where('dining_table_id', $table->id)
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->where(function ($query) use ($recentCompletedCutoff) {
                $query->where('status', '!=', Order::STATUS_COMPLETED)
                    ->orWhere('updated_at', '>=', $recentCompletedCutoff);
            })
            ->orderByDesc('id')
            ->first();

        $promosQuery = SitePromotion::withoutGlobalScope('tenant')
            ->activeForCustomer()
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->limit(6);

        if (Schema::hasColumn('site_promotions', 'tenant_id')) {
            $promoTenantId = $this->resolvePromotionTenantIdentifier($tenantId);
            if ($promoTenantId !== null && $promoTenantId !== '') {
                $promosQuery->where('tenant_id', $promoTenantId);
            }
        }

        $promos = $promosQuery->get();
        $loyaltySettings = PosLoyaltySetting::currentForTenant((string) $tenantId);

        $reservationsQuery = ReservationSpace::query()
            ->where('is_active', true)
            ->with(['images', 'items'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(4);

        if (Schema::hasColumn('reservation_spaces', 'tenant_id')) {
            $reservationsQuery->where('tenant_id', $tenantId);
        }

        $reservations = $reservationsQuery->get();

        $ratingData = $brand?->getGoogleMapsRating();
        $paymentSetting = PaymentSetting::current();
        $posPaymentSetting = PosPaymentSetting::query()
            ->where('tenant_id', $tenantId)
            ->first();
        $whatsappNumber = $organization?->phone ?: ($brand?->whatsapp ?: $brand?->phone);
        $organizationSlug = $organization?->slug;
        $customerRoot = $preferOrganizationRoot && $organizationSlug
            ? url('/customer/' . $organizationSlug)
            : ($organizationSlug
                ? url('/customer/' . $organizationSlug . '/order/' . $tableToken)
                : url('/customer/order/' . $tableToken));

        return [
            'brand' => [
                'business_name' => $organization?->name ?: ($brand?->business_name ?: 'Self Order'),
                'tagline' => $organization?->description ?: ($brand?->tagline ?: 'Selamat datang, pilih menu favorit Anda lalu kirim langsung ke dapur.'),
                'about' => $organization?->description ?: $brand?->about,
                'phone' => $organization?->phone ?: $brand?->phone,
                'whatsapp' => $organization?->phone ?: $brand?->whatsapp,
                'address' => $organization?->address ?: $brand?->address,
                'instagram' => $brand?->instagram,
                'website' => $organization?->website ?: $brand?->website,
                'primary_color' => $brand?->primary_color ?: '#0f172a',
                'secondary_color' => $brand?->secondary_color ?: '#334155',
                'accent_color' => $brand?->accent_color ?: '#f59e0b',
                'background_color' => $brand?->background_color ?: '#f8fafc',
                'logo_url' => $organization?->logo_path
                    ? url('storage/' . $organization->logo_path)
                    : ($brand?->logoDarkUrl() ?: $brand?->logoLightUrl()),
                'banner_url' => $organization?->banner_path
                    ? url('storage/' . $organization->banner_path)
                    : $brand?->homeBannerMediaUrl(),
                'banner_kind' => $organization?->banner_path
                    ? 'image'
                    : ($brand?->homeBannerIsVideo() ? 'video' : ($brand?->homeBannerMediaUrl() ? 'image' : null)),
                'google_rating' => $organization ? null : ($ratingData ? [
                    'rating' => (float) ($ratingData['rating'] ?? 0),
                    'user_ratings_total' => (int) ($ratingData['user_ratings_total'] ?? 0),
                ] : null),
            ],
            'routes' => [
                'legacy_order' => url('/order?table=' . $tableToken),
                'promo' => $customerRoot . '#promo',
                'reservations' => $customerRoot . '#reservasi',
                'member_login' => $customerRoot . '#member',
                'member_register' => $customerRoot . '#member',
                'member_dashboard' => $customerRoot . '#pesanan',
            ],
            'promos' => $promos->map(function (SitePromotion $promo) {
                return [
                    'id' => $promo->id,
                    'title' => $promo->title,
                    'promo_code' => $promo->promo_code,
                    'description' => $promo->description,
                    'terms' => $promo->terms,
                    'thumbnail_url' => $promo->thumbnailUrl(),
                    'link_url' => $promo->linkHref(),
                    'bonus_points' => (int) ($promo->bonus_points ?? 0),
                    'minimum_spend' => (int) ($promo->minimum_spend ?? 0),
                    'claim_limit' => $promo->claim_limit !== null ? (int) $promo->claim_limit : null,
                    'claimed_count' => (int) ($promo->claimed_count ?? 0),
                    'requires_reservation' => (bool) ($promo->requires_reservation ?? false),
                    'valid_until' => optional($promo->ends_at)?->toDateString(),
                ];
            })->values()->all(),
            'reservations' => $reservations->map(function (ReservationSpace $space) use ($loyaltySettings) {
                $requiredItemsTotal = $space->items->where('is_required', true)->sum(fn ($item) => (int) $item->unit_price * (int) $item->qty);
                return [
                    'id' => $space->id,
                    'name' => $space->name,
                    'location' => $space->location,
                    'capacity' => (int) $space->capacity,
                    'description' => $space->description,
                    'cover_image_url' => $space->coverImageUrl(),
                    'rent_price' => (int) $space->rent_price,
                    'rent_enabled' => (bool) $space->rent_enabled,
                    'min_menu_total' => (int) $space->min_menu_total,
                    'estimated_points' => $this->calculateEstimatedPoints($loyaltySettings, ((int) $space->rent_price) + (int) $requiredItemsTotal),
                    'images' => $space->images->map(fn ($image) => [
                        'id' => (int) $image->id,
                        'url' => $image->url(),
                        'caption' => $image->caption,
                    ])->values()->all(),
                    'items' => $space->items->map(fn ($item) => [
                        'id' => (int) $item->id,
                        'product_id' => (int) $item->product_id,
                        'product_name' => (string) $item->product_name,
                        'unit_price' => (int) $item->unit_price,
                        'qty' => (int) $item->qty,
                        'is_required' => (bool) $item->is_required,
                        'line_total' => (int) $item->line_total,
                    ])->values()->all(),
                ];
            })->values()->all(),
            'payment' => [
                'qris_static_enabled' => (bool) ($posPaymentSetting?->qris_enabled ?? false),
                'qris_static_image_url' => $posPaymentSetting?->qris_image_path
                    ? url('storage/' . $posPaymentSetting->qris_image_path)
                    : null,
                'require_paid_before_submit' => (bool) ($paymentSetting?->require_paid_before_submit ?? true),
                'whatsapp_number' => $whatsappNumber,
                'gopay_enabled' => (bool) ($posPaymentSetting?->gopay_enabled ?? false),
                'gopay_account_name' => $posPaymentSetting?->gopay_name,
                'gopay_account_number' => $posPaymentSetting?->gopay_number,
                'gopay_deeplink_template' => $posPaymentSetting?->gopay_number
                    ? 'gojek://gopay/merchant?phone=' . $this->formatDeepLinkPhone($posPaymentSetting->gopay_number) . '&amount={amount}'
                    : null,
                'dana_enabled' => (bool) ($posPaymentSetting?->dana_enabled ?? false),
                'dana_account_name' => $posPaymentSetting?->dana_name,
                'dana_account_number' => $posPaymentSetting?->dana_number,
                'dana_deeplink_template' => $posPaymentSetting?->dana_number
                    ? 'dana://wallet/deeplink/link?phoneNo=' . $this->formatDeepLinkPhone($posPaymentSetting->dana_number) . '&amount={amount}'
                    : null,
            ],
            'summary' => [
                'promo_count' => $promos->count(),
                'reservation_count' => $reservations->count(),
            ],
            'pending_order' => $pendingOrder ? $this->transformOrder($pendingOrder) : null,
        ];
    }

    private function resolveOrganization(string $tenantId): ?Organization
    {
        return Organization::query()
            ->where(function ($query) use ($tenantId) {
                $query->where('pos_tenant_slug', $tenantId)
                    ->orWhere('slug', $tenantId);
            })
            ->first();
    }

    private function resolveOrganizationSlug(string $tenantId): ?string
    {
        return $this->resolveOrganization($tenantId)?->slug;
    }

    private function resolvePromotionTenantIdentifier(string $tenantId): string|int|null
    {
        if (SitePromotion::tenantColumnUsesString()) {
            return $tenantId;
        }

        return $this->resolveOrganization($tenantId)?->id;
    }

    private function resolveActiveTableByToken(string $tableToken): ?DiningTable
    {
        return DiningTable::withoutGlobalScope('tenant')
            ->where('public_id', $tableToken)
            ->where('is_active', true)
            ->first();
    }

    private function resolvePublicEntryTable(string $tenantId): ?DiningTable
    {
        $tables = DiningTable::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        if ($tables->isEmpty()) {
            return null;
        }

        $preferred = $tables
            ->sortBy(function (DiningTable $table) {
                return sprintf(
                    '%03d-%s-%010d',
                    $this->tablePriorityScore($table),
                    strtolower((string) ($table->name ?: $table->code ?: '')),
                    (int) $table->id
                );
            })
            ->first();

        return $preferred;
    }

    private function tablePriorityScore(DiningTable $table): int
    {
        $label = strtolower(trim((string) ($table->name ?: $table->code ?: '')));

        if ($label === '') {
            return 50;
        }

        foreach (['public', 'customer', 'guest', 'online', 'umum', 'default'] as $keyword) {
            if (str_contains($label, $keyword)) {
                return 0;
            }
        }

        return 10;
    }

    private function calculateEstimatedPoints(PosLoyaltySetting $settings, int $amount): int
    {
        if (!$settings->enabled) {
            return 0;
        }

        if ($amount < (int) $settings->min_spend_amount) {
            return 0;
        }

        $points = (int) floor($amount / max(1, (int) $settings->points_per_amount));
        if ($settings->max_points_per_order !== null) {
            $points = min($points, (int) $settings->max_points_per_order);
        }

        return max(0, $points);
    }

    private function formatDeepLinkPhone(string $phone): string
    {
        $clean = preg_replace('/\D/', '', $phone) ?: '';

        if ($clean === '') {
            return '';
        }

        if (str_starts_with($clean, '0')) {
            return '+62' . substr($clean, 1);
        }

        if (str_starts_with($clean, '62')) {
            return '+' . $clean;
        }

        return '+62' . $clean;
    }
}
