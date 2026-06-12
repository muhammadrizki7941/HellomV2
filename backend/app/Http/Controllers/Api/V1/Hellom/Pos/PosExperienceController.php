<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\DiningTable;
use App\Models\PosLoyaltySetting;
use App\Models\PosMember;
use App\Models\PosPointTransaction;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\ReservationSpace;
use App\Models\SitePromotion;
use App\Models\SitePromotionClaim;
use App\Services\Reservations\ReservationBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PosExperienceController extends BasePosController
{
    public function dashboard(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        abort_unless($org, 403);

        $tenantSlug = $this->getTenantSlug($org);
        $promoTenantId = $this->resolvePromoTenantId($tenantSlug);

        \Log::info('CustomerHub tenantId', ['tenant_id' => $tenantSlug]);

        $promos = $this->promoQuery($promoTenantId)->withCount('claims')->orderBy('sort_order')->orderByDesc('id')->get();
        $spaces = $this->spaceQuery($tenantSlug)->with(['images', 'items'])->orderBy('sort_order')->orderBy('name')->get();
        $reservations = $this->reservationQuery($tenantSlug)
            ->with('space')
            ->orderByRaw("FIELD(status, 'pending', 'confirmed', 'completed', 'cancelled')")
            ->orderBy('scheduled_at')
            ->limit(80)
            ->get();
        $claims = SitePromotionClaim::query()
            ->with(['promotion', 'member'])
            ->where('tenant_id', $tenantSlug)
            ->latest()
            ->limit(80)
            ->get();
        $products = Product::query()
            ->where('is_available', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price']);
        $loyaltySettings = PosLoyaltySetting::currentForTenant($tenantSlug);

        return $this->success([
            'summary' => [
                'active_promos' => $promos->where('is_active', true)->count(),
                'promo_claims' => $claims->count(),
                'active_spaces' => $spaces->where('is_active', true)->count(),
                'pending_reservations' => $reservations->where('status', 'pending')->count(),
            ],
            'loyalty_settings' => $loyaltySettings->toPosPayload(),
            'products' => $products->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'price' => (int) $product->price,
            ])->values()->all(),
            'promos' => $promos->map(fn (SitePromotion $promo) => $this->transformPromo($promo))->values()->all(),
            'promo_claims' => $claims->map(fn (SitePromotionClaim $claim) => $this->transformClaim($claim))->values()->all(),
            'spaces' => $spaces->map(fn (ReservationSpace $space) => $this->transformSpace($space, $loyaltySettings))->values()->all(),
            'reservations' => $reservations->map(fn (Reservation $reservation) => $this->transformReservation($reservation, $loyaltySettings))->values()->all(),
        ], 'Customer experience dashboard loaded');
    }

    public function storePromo(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        abort_unless($org, 403);

        $tenantSlug = $this->getTenantSlug($org);
        $promoTenantId = $this->resolvePromoTenantId($tenantSlug);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'promo_code' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:4000'],
            'terms' => ['nullable', 'string', 'max:4000'],
            'link_url' => ['nullable', 'string', 'max:500'],
            'bonus_points' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'minimum_spend' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'claim_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'requires_reservation' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'thumbnail' => ['nullable', 'image', 'max:4096'],
        ]);

        $promo = new SitePromotion();
        $promo->fill($this->promoPayload($validated));
        $promo->tenant_id = $promoTenantId;
        $promo->slug = $this->uniquePromoSlug($promoTenantId, $validated['title']);
        $promo->claimed_count = 0;

        if ($request->hasFile('thumbnail')) {
            $promo->thumbnail_path = $request->file('thumbnail')->store('site_promotions', 'public');
        }

        $promo->save();

        return $this->success($this->transformPromo($promo->fresh('claims')), 'Promo created', 201);
    }

    public function updatePromo(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        abort_unless($org, 403);

        $tenantSlug = $this->getTenantSlug($org);
        $promoTenantId = $this->resolvePromoTenantId($tenantSlug);
        $promo = $this->promoQuery($promoTenantId)->findOrFail($id);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'promo_code' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:4000'],
            'terms' => ['nullable', 'string', 'max:4000'],
            'link_url' => ['nullable', 'string', 'max:500'],
            'bonus_points' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'minimum_spend' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'claim_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'requires_reservation' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'thumbnail' => ['nullable', 'image', 'max:4096'],
            'remove_thumbnail' => ['nullable', 'boolean'],
        ]);

        $originalTitle = $promo->title;
        $promo->fill($this->promoPayload($validated));

        if (!empty($validated['title']) && $validated['title'] !== $originalTitle) {
            $promo->slug = $this->uniquePromoSlug($promoTenantId, $validated['title'], $promo->id);
        }

        if (($validated['remove_thumbnail'] ?? false) && $promo->thumbnail_path) {
            Storage::disk('public')->delete($promo->thumbnail_path);
            $promo->thumbnail_path = null;
        }

        if ($request->hasFile('thumbnail')) {
            if ($promo->thumbnail_path) {
                Storage::disk('public')->delete($promo->thumbnail_path);
            }
            $promo->thumbnail_path = $request->file('thumbnail')->store('site_promotions', 'public');
        }

        $promo->save();

        return $this->success($this->transformPromo($promo->fresh('claims')), 'Promo updated');
    }

    public function destroyPromo(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        abort_unless($org, 403);

        $tenantSlug = $this->getTenantSlug($org);
        $promo = $this->promoQuery($this->resolvePromoTenantId($tenantSlug))->findOrFail($id);
        if ($promo->thumbnail_path) {
            Storage::disk('public')->delete($promo->thumbnail_path);
        }
        $promo->delete();

        return $this->success(null, 'Promo deleted');
    }

    public function storeSpace(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        abort_unless($org, 403);
        $tenantSlug = $this->getTenantSlug($org);

        $validated = $request->validate($this->spaceRules());
        $space = ReservationSpace::query()->create([
            'tenant_id' => $tenantSlug,
            'name' => $validated['name'],
            'slug' => $this->uniqueSpaceSlug($tenantSlug, $validated['name']),
            'location' => $validated['location'] ?? null,
            'capacity' => $validated['capacity'] ?? null,
            'description' => $validated['description'] ?? null,
            'rent_price' => (int) ($validated['rent_price'] ?? 0),
            'rent_enabled' => (bool) ($validated['rent_enabled'] ?? true),
            'min_menu_total' => (int) ($validated['min_menu_total'] ?? 0),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $this->syncSpaceItems($space, $validated['items'] ?? []);
        $this->appendSpaceImages($request, $space);

        return $this->success(
            $this->transformSpace($space->fresh(['images', 'items']), PosLoyaltySetting::currentForTenant($tenantSlug)),
            'Reservation space created',
            201
        );
    }

    public function updateSpace(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        abort_unless($org, 403);
        $tenantSlug = $this->getTenantSlug($org);

        $space = $this->spaceQuery($tenantSlug)->with(['images', 'items'])->findOrFail($id);
        $validated = $request->validate($this->spaceRules());

        $space->update([
            'tenant_id' => $space->tenant_id ?: $tenantSlug,
            'name' => $validated['name'],
            'slug' => $this->uniqueSpaceSlug($tenantSlug, $validated['name'], $space->id),
            'location' => $validated['location'] ?? null,
            'capacity' => $validated['capacity'] ?? null,
            'description' => $validated['description'] ?? null,
            'rent_price' => (int) ($validated['rent_price'] ?? 0),
            'rent_enabled' => (bool) ($validated['rent_enabled'] ?? true),
            'min_menu_total' => (int) ($validated['min_menu_total'] ?? 0),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $deleteImageIds = collect($validated['delete_image_ids'] ?? [])->map(fn ($value) => (int) $value)->filter()->values();
        if ($deleteImageIds->isNotEmpty()) {
            $space->images->whereIn('id', $deleteImageIds)->each(function ($image) {
                if ($image->image_path) {
                    Storage::disk('public')->delete($image->image_path);
                }
                $image->delete();
            });
        }

        $this->syncSpaceItems($space, $validated['items'] ?? []);
        $this->appendSpaceImages($request, $space);

        return $this->success(
            $this->transformSpace($space->fresh(['images', 'items']), PosLoyaltySetting::currentForTenant($tenantSlug)),
            'Reservation space updated'
        );
    }

    public function destroySpace(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        abort_unless($org, 403);
        $tenantSlug = $this->getTenantSlug($org);

        $space = $this->spaceQuery($tenantSlug)->with('images')->findOrFail($id);
        foreach ($space->images as $image) {
            if ($image->image_path) {
                Storage::disk('public')->delete($image->image_path);
            }
        }
        $space->delete();

        return $this->success(null, 'Reservation space deleted');
    }

    public function updateReservationStatus(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        abort_unless($org, 403);
        $tenantSlug = $this->getTenantSlug($org);
        $validated = $request->validate([
            'status' => ['required', 'in:pending,confirmed,completed,cancelled'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $reservation = $this->reservationQuery($tenantSlug)->findOrFail($id);
        $reservation->update([
            'status' => $validated['status'],
            'admin_notes' => $validated['admin_notes'] ?? null,
            'tenant_id' => $reservation->tenant_id ?: $tenantSlug,
        ]);

        return $this->success(
            $this->transformReservation($reservation->fresh('space'), PosLoyaltySetting::currentForTenant($tenantSlug)),
            'Reservation updated'
        );
    }

    public function claimPromo(Request $request, int $promoId): JsonResponse
    {
        $validated = $request->validate([
            'table_token' => ['required', 'string'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:40'],
            'customer_email' => ['nullable', 'email', 'max:160'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $table = DiningTable::query()->where('public_id', $validated['table_token'])->where('is_active', true)->firstOrFail();
        $tenantSlug = (string) $table->tenant_id;
        $promo = $this->promoQuery($this->resolvePromoTenantId($tenantSlug))
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->findOrFail($promoId);

        if ($promo->claim_limit !== null && $promo->claimed_count >= $promo->claim_limit) {
            return $this->error('Promo sudah habis diambil.', 'PROMO_LIMIT_REACHED', null, 422);
        }

        [$member, $wasCreated] = $this->findOrCreateMember(
            $tenantSlug,
            $validated['customer_name'],
            $validated['customer_phone'],
            $validated['customer_email'] ?? null,
        );

        $existingClaim = SitePromotionClaim::query()
            ->where('tenant_id', $tenantSlug)
            ->where('site_promotion_id', $promo->id)
            ->where('pos_member_id', $member?->id)
            ->first();

        if ($existingClaim) {
            return $this->success([
                'claim' => $this->transformClaim($existingClaim->load(['promotion', 'member'])),
                'member' => $member ? $this->transformMember($member) : null,
                'created_member' => $wasCreated,
                'already_claimed' => true,
            ], 'Promo sudah pernah diambil customer ini.');
        }

        $awardedPoints = 0;
        $claim = null;
        DB::transaction(function () use ($promo, $tenantSlug, $member, $validated, &$awardedPoints, &$claim) {
            $claim = SitePromotionClaim::query()->create([
                'tenant_id' => $tenantSlug,
                'site_promotion_id' => $promo->id,
                'pos_member_id' => $member?->id,
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'customer_email' => $validated['customer_email'] ?? null,
                'claim_code' => $promo->promo_code ?: strtoupper(Str::upper(Str::random(8))),
                'claimed_via' => 'customer_order',
                'metadata' => [
                    'notes' => $validated['notes'] ?? null,
                ],
            ]);

            $promo->increment('claimed_count');

            $settings = PosLoyaltySetting::currentForTenant($tenantSlug);
            $awardedPoints = $settings->enabled && $member ? max(0, (int) $promo->bonus_points) : 0;

            if ($awardedPoints > 0 && $member) {
                $member->total_points = (int) $member->total_points + $awardedPoints;
                $member->redeemable_points = (int) $member->redeemable_points + $awardedPoints;
                $member->save();

                PosPointTransaction::query()->create([
                    'tenant_id' => $tenantSlug,
                    'member_id' => $member->id,
                    'order_id' => null,
                    'type' => 'bonus',
                    'points' => $awardedPoints,
                    'balance_after' => (int) $member->redeemable_points,
                    'description' => 'Bonus poin promo ' . $promo->title,
                    'metadata' => [
                        'site_promotion_id' => $promo->id,
                    ],
                ]);

                $claim->bonus_points_awarded = $awardedPoints;
                $claim->save();
            }
        });

        return $this->success([
            'claim' => $this->transformClaim($claim->load(['promotion', 'member'])),
            'member' => $member ? $this->transformMember($member->fresh()) : null,
            'created_member' => $wasCreated,
            'already_claimed' => false,
            'awarded_points' => $awardedPoints,
        ], 'Promo berhasil diambil.');
    }

    public function createReservation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'table_token' => ['required', 'string'],
            'reservation_space_id' => ['required', 'integer'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:40'],
            'customer_email' => ['nullable', 'email', 'max:160'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:30', 'max:720'],
            'guests_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'selected_space_items' => ['nullable', 'array'],
            'selected_space_items.*.item_id' => ['required', 'integer'],
            'selected_space_items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'menu_items' => ['nullable', 'array'],
            'menu_items.*.product_id' => ['required', 'integer'],
            'menu_items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $table = DiningTable::query()->where('public_id', $validated['table_token'])->where('is_active', true)->firstOrFail();
        $tenantSlug = (string) $table->tenant_id;

        $space = $this->spaceQuery($tenantSlug)
            ->where('is_active', true)
            ->with('items')
            ->findOrFail((int) $validated['reservation_space_id']);

        $validated['menu_items'] = array_values(array_map(fn ($row) => [
            'product_id' => (int) $row['product_id'],
            'qty' => (int) $row['qty'],
        ], $validated['menu_items'] ?? []));

        $booking = app(ReservationBookingService::class);
        try {
            $booking->ensureAvailableOrFail($space, Carbon::parse($validated['scheduled_at']), (int) $validated['duration_minutes']);
            $reservation = $booking->createReservation(null, $space, $validated);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 'RESERVATION_NOT_AVAILABLE', null, 422);
        }

        [$member] = $this->findOrCreateMember(
            $tenantSlug,
            $validated['customer_name'],
            $validated['customer_phone'],
            $validated['customer_email'] ?? null,
        );

        $loyaltySettings = PosLoyaltySetting::currentForTenant($tenantSlug);

        return $this->success([
            'reservation' => $this->transformReservation($reservation->fresh('space'), $loyaltySettings),
            'member' => $member ? $this->transformMember($member) : null,
            'estimated_points' => $this->calculateEstimatedPoints($loyaltySettings, (int) $reservation->total_price),
        ], 'Reservasi berhasil dikirim.', 201);
    }

    private function promoPayload(array $validated): array
    {
        return [
            'title' => $validated['title'],
            'promo_code' => $validated['promo_code'] ? Str::upper(trim((string) $validated['promo_code'])) : null,
            'description' => $validated['description'] ?? null,
            'terms' => $validated['terms'] ?? null,
            'link_url' => $validated['link_url'] ?? null,
            'bonus_points' => (int) ($validated['bonus_points'] ?? 0),
            'minimum_spend' => (int) ($validated['minimum_spend'] ?? 0),
            'claim_limit' => $validated['claim_limit'] ?? null,
            'requires_reservation' => (bool) ($validated['requires_reservation'] ?? false),
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }

    private function spaceRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'rent_price' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'rent_enabled' => ['nullable', 'boolean'],
            'min_menu_total' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
            'delete_image_ids' => ['nullable', 'array'],
            'delete_image_ids.*' => ['integer'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:4096'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'items.*.is_required' => ['nullable', 'boolean'],
        ];
    }

    private function syncSpaceItems(ReservationSpace $space, array $items): void
    {
        $space->loadMissing('items');
        $existing = $space->items->keyBy('id');
        $keptIds = [];

        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }

            $product = Product::query()->findOrFail((int) $row['product_id']);
            $payload = [
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->name,
                'unit_price' => (int) $product->price,
                'qty' => (int) $row['qty'],
                'is_required' => (bool) ($row['is_required'] ?? false),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];

            $itemId = isset($row['id']) ? (int) $row['id'] : null;
            if ($itemId && $existing->has($itemId)) {
                $space->items()->whereKey($itemId)->update($payload);
                $keptIds[] = $itemId;
                continue;
            }

            $created = $space->items()->create($payload);
            $keptIds[] = (int) $created->id;
        }

        $space->items()->whereNotIn('id', $keptIds ?: [0])->delete();
    }

    private function appendSpaceImages(Request $request, ReservationSpace $space): void
    {
        if (!$request->hasFile('images')) {
            return;
        }

        $baseSortOrder = (int) (($space->images()->max('sort_order') ?? 0) + 1);
        foreach (array_values($request->file('images') ?? []) as $index => $file) {
            if (!$file) {
                continue;
            }

            $space->images()->create([
                'image_path' => $file->store('reservation_spaces', 'public'),
                'caption' => $space->name,
                'sort_order' => $baseSortOrder + $index,
            ]);
        }
    }

    private function promoQuery(string|int|null $tenantId)
    {
        $query = SitePromotion::withoutGlobalScope('tenant');
        if (Schema::hasColumn('site_promotions', 'tenant_id') && $tenantId !== null && $tenantId !== '') {
            $query->where('tenant_id', $tenantId);
        }

        return $query;
    }

    private function spaceQuery(string $tenantSlug)
    {
        return ReservationSpace::query()->where(function ($builder) use ($tenantSlug) {
            if (Schema::hasColumn('reservation_spaces', 'tenant_id')) {
                $builder->where('tenant_id', $tenantSlug);
                return;
            }

            $builder->whereNotNull('id');
        });
    }

    private function reservationQuery(string $tenantSlug)
    {
        return Reservation::query()->where(function ($builder) use ($tenantSlug) {
            if (Schema::hasColumn('reservations', 'tenant_id')) {
                $builder->where('tenant_id', $tenantSlug)->orWhereNull('tenant_id');
                return;
            }

            $builder->whereNotNull('id');
        });
    }

    private function uniquePromoSlug(string|int|null $tenantId, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'promo';
        $slug = $base;
        $suffix = 2;

        while ($this->promoQuery($tenantId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function resolvePromoTenantId(string $tenantSlug): string|int|null
    {
        if (!Schema::hasColumn('site_promotions', 'tenant_id')) {
            return null;
        }

        if (SitePromotion::tenantColumnUsesString()) {
            return $tenantSlug;
        }

        $organization = \App\Models\Organization::query()
            ->where('pos_tenant_slug', $tenantSlug)
            ->orWhere('slug', $tenantSlug)
            ->first();

        return $organization?->id;
    }

    private function uniqueSpaceSlug(string $tenantSlug, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'space';
        $slug = $base;
        $suffix = 2;

        while ($this->spaceQuery($tenantSlug)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function transformPromo(SitePromotion $promo): array
    {
        return [
            'id' => (int) $promo->id,
            'title' => (string) $promo->title,
            'promo_code' => $promo->promo_code,
            'description' => $promo->description,
            'terms' => $promo->terms,
            'thumbnail_url' => $promo->thumbnailUrl(),
            'link_url' => $promo->linkHref(),
            'bonus_points' => (int) ($promo->bonus_points ?? 0),
            'minimum_spend' => (int) ($promo->minimum_spend ?? 0),
            'claim_limit' => $promo->claim_limit !== null ? (int) $promo->claim_limit : null,
            'claimed_count' => (int) ($promo->claimed_count ?? $promo->claims_count ?? 0),
            'requires_reservation' => (bool) ($promo->requires_reservation ?? false),
            'starts_at' => optional($promo->starts_at)?->toIso8601String(),
            'ends_at' => optional($promo->ends_at)?->toIso8601String(),
            'valid_until' => optional($promo->ends_at)?->toDateString(),
            'is_active' => (bool) $promo->is_active,
            'sort_order' => (int) $promo->sort_order,
        ];
    }

    private function transformClaim(SitePromotionClaim $claim): array
    {
        return [
            'id' => (int) $claim->id,
            'promo' => $claim->relationLoaded('promotion') && $claim->promotion
                ? [
                    'id' => (int) $claim->promotion->id,
                    'title' => (string) $claim->promotion->title,
                    'promo_code' => $claim->promotion->promo_code,
                ]
                : null,
            'member' => $claim->relationLoaded('member') && $claim->member ? $this->transformMember($claim->member) : null,
            'customer_name' => (string) $claim->customer_name,
            'customer_phone' => $claim->customer_phone,
            'customer_email' => $claim->customer_email,
            'claim_code' => $claim->claim_code,
            'bonus_points_awarded' => (int) $claim->bonus_points_awarded,
            'claimed_via' => $claim->claimed_via,
            'created_at' => optional($claim->created_at)?->toIso8601String(),
        ];
    }

    private function transformMember(PosMember $member): array
    {
        return [
            'id' => (int) $member->id,
            'name' => (string) $member->name,
            'phone' => $member->phone,
            'email' => $member->email,
            'total_points' => (int) $member->total_points,
            'redeemable_points' => (int) $member->redeemable_points,
            'total_orders' => (int) $member->total_orders,
            'total_spent' => (int) $member->total_spent,
            'tier' => $member->tier,
        ];
    }

    private function transformSpace(ReservationSpace $space, PosLoyaltySetting $settings): array
    {
        $space->loadMissing(['images', 'items']);
        $spaceBaseTotal = ($space->rent_enabled ? (int) $space->rent_price : 0)
            + (int) $space->items->where('is_required', true)->sum(fn ($item) => (int) $item->unit_price * (int) $item->qty);

        return [
            'id' => (int) $space->id,
            'name' => (string) $space->name,
            'slug' => (string) $space->slug,
            'location' => $space->location,
            'capacity' => (int) ($space->capacity ?? 0),
            'description' => $space->description,
            'rent_price' => (int) $space->rent_price,
            'rent_enabled' => (bool) $space->rent_enabled,
            'min_menu_total' => (int) $space->min_menu_total,
            'sort_order' => (int) $space->sort_order,
            'is_active' => (bool) $space->is_active,
            'cover_image_url' => $space->coverImageUrl(),
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
                'sort_order' => (int) $item->sort_order,
                'line_total' => (int) $item->line_total,
            ])->values()->all(),
            'estimated_points' => $this->calculateEstimatedPoints($settings, $spaceBaseTotal),
        ];
    }

    private function transformReservation(Reservation $reservation, PosLoyaltySetting $settings): array
    {
        return [
            'id' => (int) $reservation->id,
            'space_name' => (string) $reservation->space_name,
            'reservation_space_id' => (int) $reservation->reservation_space_id,
            'customer_name' => (string) $reservation->customer_name,
            'customer_phone' => (string) $reservation->customer_phone,
            'customer_email' => $reservation->customer_email,
            'scheduled_at' => optional($reservation->scheduled_at)?->toIso8601String(),
            'duration_minutes' => (int) $reservation->duration_minutes,
            'guests_count' => (int) ($reservation->guests_count ?? 0),
            'notes' => $reservation->notes,
            'admin_notes' => $reservation->admin_notes,
            'status' => (string) $reservation->status,
            'rent_price' => (int) $reservation->rent_price,
            'items_total' => (int) $reservation->items_total,
            'menu_commitment_total' => (int) $reservation->menu_commitment_total,
            'total_price' => (int) $reservation->total_price,
            'estimated_points' => $this->calculateEstimatedPoints($settings, (int) $reservation->total_price),
            'items_snapshot' => is_array($reservation->items_snapshot) ? $reservation->items_snapshot : [],
            'menu_order_snapshot' => is_array($reservation->menu_order_snapshot) ? $reservation->menu_order_snapshot : [],
        ];
    }

    private function calculateEstimatedPoints(PosLoyaltySetting $settings, int $amount): int
    {
        if (!$settings->enabled) {
            return 0;
        }

        if ($amount < (int) $settings->min_spend_amount) {
            return 0;
        }

        $pointsPerAmount = max(1, (int) $settings->points_per_amount);
        $points = (int) floor($amount / $pointsPerAmount);
        if ($settings->max_points_per_order !== null) {
            $points = min($points, (int) $settings->max_points_per_order);
        }

        return max(0, $points);
    }

    private function findOrCreateMember(string $tenantSlug, string $name, string $phone, ?string $email): array
    {
        $query = PosMember::query()->where('tenant_id', $tenantSlug);

        $member = $query->when($phone !== '', fn ($builder) => $builder->where('phone', $phone))
            ->when($phone === '' && $email, fn ($builder) => $builder->where('email', $email))
            ->first();

        if ($member) {
            $member->fill([
                'name' => $name,
                'phone' => $phone ?: $member->phone,
                'email' => $email ?: $member->email,
            ]);
            $member->save();

            return [$member, false];
        }

        $member = PosMember::updateOrCreate(
            [
                'tenant_id' => $tenantSlug,
                'email'     => $email,
            ],
            [
                'name'  => $name,
                'phone' => $phone,
            ]
        );
        return [$member, true];
    }
}
