<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Listeners\Pos\OrderSideEffectsSubscriber;
use App\Models\BrandSetting;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Organization;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\PosLoyaltySetting;
use App\Models\Product;
use App\Models\ReservationSpace;
use App\Models\SitePromotion;
use App\Models\TableBill;
use App\Services\Pos\LoyaltyService;
use App\Services\Pos\MemberService;
use App\Services\Pos\OrderService;
use App\Services\Pos\OrderStatus;
use App\Services\Pos\OutletSettings;
use App\Services\Pos\PricingException;
use App\Services\Pos\SelfOrderGate;
use App\Services\Realtime\RealtimeTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Public self-order (QR per table, or the shop link which orders through the outlet's
 * counter pseudo-table). Orders go through the same OrderService as the cashier, so a
 * cart costs the same everywhere; the menu, prices, hours and payment methods are the
 * outlet's own.
 */
class CustomerOrderController extends BaseApiController
{
    public function __construct(
        private readonly SelfOrderGate $gate,
        private readonly OrderService $orders,
        private readonly MemberService $members,
    ) {
    }

    public function getMenu(string $tableToken): JsonResponse
    {
        $resolved = $this->gate->resolveTable($tableToken);
        if (!$resolved) {
            return $this->fail('Meja tidak ditemukan atau QR sudah tidak berlaku. Minta QR terbaru ke kasir.', ['code' => 'TABLE_NOT_FOUND'], 404);
        }
        [$table, $outlet] = $resolved;

        return $this->menuResponse($table, $outlet, $tableToken);
    }

    public function getOrganizationMenu(string $organizationSlug, Request $request): JsonResponse
    {
        $organization = Organization::query()->where('slug', $organizationSlug)->first();
        if (!$organization) {
            return $this->fail('Organization not found', [], 404);
        }

        // Resolve the chosen outlet (?outlet=slug|id), defaulting to the primary outlet.
        $outlet = $this->resolveOrganizationOutlet($organization, $request->query('outlet'));
        if (!$outlet) {
            return $this->fail('Outlet tidak ditemukan atau tidak aktif.', [], 404);
        }
        $counter = $this->gate->counterTable($outlet);

        return $this->menuResponse($counter, $outlet, (string) $counter->public_id, true);
    }

    /**
     * Public list of an organization's active outlets so the self-order page can
     * let the customer pick a branch (each has its own address & menu).
     */
    public function getOrganizationOutlets(string $organizationSlug): JsonResponse
    {
        $organization = Organization::query()
            ->where('slug', $organizationSlug)
            ->first();

        if (!$organization) {
            return $this->fail('Organization not found', [], 404);
        }

        $outlets = Outlet::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Outlet $outlet) => [
                'id' => $outlet->id,
                'slug' => $outlet->slug,
                'name' => $outlet->name,
                'address' => $outlet->address,
                'phone' => $outlet->phone,
                'is_primary' => (bool) $outlet->is_primary,
                'status' => $this->gate->status($outlet),
            ])
            ->values();

        return $this->ok([
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
            ],
            'outlets' => $outlets,
        ], 'Outlets retrieved successfully');
    }

    public function createOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'table_token' => 'required|string|max:64',
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'items.*.options' => 'nullable|array',
            'items.*.options.*.option_id' => 'nullable|integer',
            'items.*.options.*.value_id' => 'nullable|integer',
            // The unit price the customer saw; a difference is reported back instead of silently charged.
            'items.*.expected_unit_price' => 'nullable|integer|min:0',
            'customer_name' => 'nullable|string|max:100',
            'customer_phone' => 'nullable|string|max:20',
            'register_member' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
            'payment_method' => 'nullable|string|max:20',
            'payment_confirmed' => 'nullable|boolean',
        ]);

        $resolved = $this->gate->resolveTable($validated['table_token']);
        if (!$resolved) {
            return $this->fail('Meja tidak ditemukan atau QR sudah tidak berlaku. Minta QR terbaru ke kasir.', ['code' => 'TABLE_NOT_FOUND'], 404);
        }
        [$table, $outlet] = $resolved;

        $method = $validated['payment_method'] ?? 'cash';
        if ($method === 'qris_static') {
            $method = 'qris';
        }
        if (!in_array($method, $this->gate->paymentMethods($outlet), true)) {
            return $this->fail("Metode pembayaran {$method} tidak tersedia di outlet ini.", ['code' => 'PAYMENT_METHOD_UNAVAILABLE'], 422);
        }

        try {
            $this->gate->assertCanOrder($outlet, $table);
            $organization = $this->orders->organizationOf($outlet);

            $member = null;
            $phone = $validated['customer_phone'] ?? null;
            if ($phone) {
                if (!empty($validated['register_member'])) {
                    if (blank($validated['customer_name'] ?? null)) {
                        throw new PricingException('Isi nama untuk mendaftar member.', [], 'MEMBER_NAME_REQUIRED');
                    }
                    [$member] = $this->members->register($organization, $validated['customer_name'], $phone, null, $outlet->id, $outlet->tenant_slug);
                } else {
                    $member = $this->members->findByPhone($organization, $phone);
                }
            }

            $isCounter = $table->kind === DiningTable::KIND_COUNTER;
            $order = $this->orders->create($outlet, [
                'items' => $validated['items'],
                'member' => $member,
                'source' => OrderService::SOURCE_SELF_ORDER,
                'dining_table' => $table,
                'service_type' => $isCounter ? 'takeaway' : 'dine_in',
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $phone,
                'notes' => $validated['notes'] ?? null,
                'payment_method' => $method,
                'auto_confirm' => !OutletSettings::for($outlet)->selfOrderNeedsConfirmation(),
            ]);
        } catch (PricingException $e) {
            return $this->orderRuleFailed($e);
        }

        $order->load(['items', 'table']);

        return $this->ok([
            'order' => $this->transformOrder($order),
            'member' => $member ? ['id' => $member->id, 'name' => $member->name, 'points' => (int) $member->redeemable_points] : null,
        ], 'Pesanan terkirim', 201);
    }

    public function getOrderStatus(Request $request, string $orderNumber): JsonResponse
    {
        // Order numbers are sequential per outlet, so the number alone must not reveal
        // an order: the guest also proves the table (token from the QR code).
        $tableToken = trim((string) $request->query('table_token', ''));
        if ($tableToken === '') {
            return $this->fail('Order not found', [], 404);
        }

        // Active or not: a table disabled after ordering must not hide the order.
        $table = DiningTable::withoutGlobalScope('tenant')->where('public_id', $tableToken)->first();
        if (!$table instanceof DiningTable) {
            return $this->fail('Order not found', [], 404);
        }

        $order = Order::withoutGlobalScope('tenant')
            ->with(['items', 'table'])
            ->where('order_number', $orderNumber)
            ->where('tenant_id', $table->tenant_id)
            ->where('dining_table_id', $table->id)
            ->first();

        if (!$order) {
            return $this->fail('Order not found', [], 404);
        }

        return $this->ok([
            'order' => $this->transformOrder($order),
        ], 'Order retrieved successfully');
    }

    /** Every order on the table's open bill (self-order and cashier alike). Not for the shared counter. */
    public function getTableOrders(string $tableToken): JsonResponse
    {
        $resolved = $this->gate->resolveTable($tableToken);
        if (!$resolved) {
            return $this->fail('Meja tidak ditemukan', ['code' => 'TABLE_NOT_FOUND'], 404);
        }
        [$table] = $resolved;
        if ($table->kind === DiningTable::KIND_COUNTER) {
            return $this->ok(['bill' => null, 'orders' => []], 'Tidak ada tagihan meja');
        }

        $bill = TableBill::query()->where('dining_table_id', $table->id)->where('status', TableBill::STATUS_OPEN)->first();
        $orders = $bill
            ? Order::withoutGlobalScope('tenant')->with(['items', 'table'])->where('table_bill_id', $bill->id)->orderBy('id')->get()
            : collect();
        $live = $orders->where('status', '!=', Order::STATUS_CANCELLED);

        return $this->ok([
            'bill' => $bill ? [
                'id' => $bill->id,
                'opened_at' => optional($bill->opened_at)->toIso8601String(),
                'total_amount' => (int) $live->sum('final_amount'),
                'unpaid_amount' => (int) $live->where('payment_status', Order::PAYMENT_UNPAID)->sum('final_amount'),
            ] : null,
            'orders' => $orders->map(fn (Order $o) => $this->transformOrder($o))->values(),
        ], 'Pesanan meja');
    }

    /** Socket token that only joins this table's room (status updates without polling). */
    public function realtimeToken(string $tableToken, RealtimeTokenService $tokens): JsonResponse
    {
        $resolved = $this->gate->resolveTable($tableToken);
        if (!$resolved) {
            return $this->fail('Meja tidak ditemukan', ['code' => 'TABLE_NOT_FOUND'], 404);
        }
        [$table] = $resolved;
        $room = OrderSideEffectsSubscriber::tableRoom((int) $table->id);
        $issued = $tokens->issueFor(0, [$room]);

        return $this->ok([
            'enabled' => $issued !== null,
            'token' => $issued['token'] ?? null,
            'expires_at' => $issued['expires_at'] ?? null,
            'poll_seconds' => 15,
        ], 'Realtime token');
    }

    private function resolveOrganizationOutlet(Organization $organization, mixed $outletParam): ?Outlet
    {
        $query = Outlet::query()->where('organization_id', $organization->id)->where('is_active', true);
        if ($outletParam !== null && $outletParam !== '') {
            $outlet = is_numeric($outletParam)
                ? (clone $query)->where('id', (int) $outletParam)->first()
                : (clone $query)->where('slug', (string) $outletParam)->first();
            if ($outlet && !empty($outlet->tenant_slug)) {
                return $outlet;
            }
        }

        return (clone $query)->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')->first();
    }

    private function transformOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'status_label' => OrderStatus::LABELS[$order->status] ?? $order->status,
            'customer_name' => $order->customer_name,
            'table' => $order->relationLoaded('table') && $order->table
                ? [
                    'id' => $order->table->id,
                    'code' => $order->table->code,
                    'name' => $order->table->name,
                ]
                : null,
            'table_label' => $order->table_label,
            'table_bill_id' => $order->table_bill_id,
            'service_type' => $order->service_type,
            'order_source' => $order->order_source,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'notes' => $order->notes,
            'subtotal_amount' => (int) ($order->subtotal_amount ?? $order->total_amount),
            'service_amount' => (int) $order->service_amount,
            'tax_amount' => (int) $order->tax_amount,
            'rounding_amount' => (int) $order->rounding_amount,
            'discount_amount' => (int) $order->discount_amount,
            'total_amount' => $order->total_amount,
            'final_amount' => $order->final_amount ?? $order->total_amount,
            'cancel_reason' => $order->cancel_reason,
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

    private function menuResponse(DiningTable $table, Outlet $outlet, string $tableToken, bool $preferOrganizationRoot = false): JsonResponse
    {
        $tenantId = (string) $outlet->tenant_slug;

        $products = Product::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->with(['category', 'options' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'), 'options.values'])
            ->orderBy('category_id')
            ->orderBy('sort_order')
            ->get()
            ->reject(fn (Product $product) => $product->hide_when_unavailable && !$product->isAvailableNow());

        $categories = Category::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $categoriesWithProducts = $categories->map(function ($category) use ($products) {
            $categoryProducts = $products->filter(fn ($product) => $product->category_id === $category->id);

            return [
                'id' => $category->id,
                'name' => $category->name,
                'products' => $categoryProducts->map(function (Product $product) {
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
                        'options' => $product->options->map(fn ($option) => [
                            'id' => $option->id,
                            'name' => $option->name,
                            'type' => $option->type,
                            'is_required' => (bool) $option->is_required,
                            'values' => $option->values
                                ->filter(fn ($value) => (bool) ($value->is_active ?? true))
                                ->map(fn ($value) => ['id' => $value->id, 'name' => $value->name, 'price_delta' => (int) $value->price_delta])
                                ->values(),
                        ])->values(),
                    ];
                })->values(),
            ];
        });

        $settings = OutletSettings::for($outlet);
        $organization = Organization::query()->find($outlet->organization_id);

        return response()->json([
            'success' => true,
            'data' => [
                'table' => [
                    'id' => $table->id,
                    'public_id' => $table->public_id,
                    'code' => $table->code,
                    'name' => $table->name,
                    'kind' => $table->kind ?? DiningTable::KIND_TABLE,
                    'tenant_slug' => $tenantId,
                    'organization_slug' => $organization?->slug,
                ],
                'outlet' => [
                    'id' => $outlet->id,
                    'slug' => $outlet->slug,
                    'name' => $outlet->name,
                    'address' => $outlet->address,
                    'phone' => $outlet->phone,
                    'is_primary' => (bool) $outlet->is_primary,
                    'status' => $this->gate->status($outlet),
                    'pricing' => [
                        'tax_percent' => $settings->taxPercent(),
                        'service_percent' => $settings->servicePercent(),
                        'rounding_step' => $settings->roundingStep(),
                    ],
                    'payment_methods' => $this->gate->paymentMethods($outlet),
                ],
                'categories' => $categoriesWithProducts,
                'experience' => $this->buildCustomerExperience($table, $outlet, $organization, $tableToken, $preferOrganizationRoot),
            ],
            'message' => 'Menu retrieved successfully',
        ]);
    }

    private function buildCustomerExperience(DiningTable $table, Outlet $outlet, ?Organization $organization, string $tableToken, bool $preferOrganizationRoot = false): array
    {
        $tenantId = (string) $outlet->tenant_slug;
        // Platform branding is only a fallback for legacy tables with no organization.
        $brand = $organization ? null : BrandSetting::current();
        $recentCompletedCutoff = now()->subMinutes(5);

        $pendingOrder = $table->kind === DiningTable::KIND_COUNTER ? null : Order::withoutGlobalScope('tenant')
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
            $promoTenantId = SitePromotion::tenantColumnUsesString() ? $tenantId : $organization?->id;
            if ($promoTenantId !== null && $promoTenantId !== '') {
                $promosQuery->where('tenant_id', $promoTenantId);
            }
        }

        $promos = $promosQuery->get();
        $loyaltySettings = $organization
            ? PosLoyaltySetting::currentForTenant(LoyaltyService::organizationSlug($organization))
            : PosLoyaltySetting::currentForTenant($tenantId);

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
        $posPaymentSetting = $this->gate->paymentSetting($outlet);
        $whatsappNumber = $outlet->phone ?: ($organization?->phone ?: ($brand?->whatsapp ?: $brand?->phone));
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
                'phone' => $outlet->phone ?: ($organization?->phone ?: $brand?->phone),
                'whatsapp' => $outlet->phone ?: ($organization?->phone ?: $brand?->whatsapp),
                'address' => $outlet->address ?: ($organization?->address ?: $brand?->address),
                'instagram' => $brand?->instagram,
                'website' => $organization?->website ?: $brand?->website,
                // Tenants have no colour settings yet: neutral defaults, never the platform's palette.
                'primary_color' => $brand?->primary_color ?: '#0f172a',
                'secondary_color' => $brand?->secondary_color ?: '#334155',
                'accent_color' => $brand?->accent_color ?: '#f59e0b',
                'background_color' => $brand?->background_color ?: '#f8fafc',
                'logo_url' => ($outlet->logo_path ?: $organization?->logo_path)
                    ? url('storage/' . ($outlet->logo_path ?: $organization->logo_path))
                    : ($brand?->logoDarkUrl() ?: $brand?->logoLightUrl()),
                'banner_url' => ($outlet->banner_path ?: $organization?->banner_path)
                    ? url('storage/' . ($outlet->banner_path ?: $organization->banner_path))
                    : $brand?->homeBannerMediaUrl(),
                'banner_kind' => ($outlet->banner_path ?: $organization?->banner_path)
                    ? 'image'
                    : ($brand?->homeBannerIsVideo() ? 'video' : ($brand?->homeBannerMediaUrl() ? 'image' : null)),
                'google_rating' => $organization ? null : ($ratingData ? [
                    'rating' => (float) ($ratingData['rating'] ?? 0),
                    'user_ratings_total' => (int) ($ratingData['user_ratings_total'] ?? 0),
                ] : null),
            ],
            'outlet' => [
                'id' => $outlet->id,
                'slug' => $outlet->slug,
                'name' => $outlet->name,
                'address' => $outlet->address,
                'phone' => $outlet->phone,
                'is_primary' => (bool) $outlet->is_primary,
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
                    'estimated_points' => app(LoyaltyService::class)->pointsForSpend($loyaltySettings, ((int) $space->rent_price) + (int) $requiredItemsTotal),
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
                'methods' => $this->gate->paymentMethods($outlet),
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
