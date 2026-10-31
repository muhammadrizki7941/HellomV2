<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\AppCatalog;
use App\Models\DigitalProduct;
use App\Models\ProductPurchase;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberDashboardController extends BaseApiController
{
    public function cards(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $apps = AppCatalog::query()
            ->where('is_active', true)
            ->with(['entitlements' => function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId)->with('plan');
            }])
            ->orderBy('id')
            ->get();

        $cards = $apps->map(function (AppCatalog $app) {
            $entitlement = $app->entitlements->first();
            $status = $entitlement?->effectiveStatus() ?? 'locked';
            $allowed = (bool) $entitlement?->allowsAccess();
            // Kalkulator HPP is free for every shop; its entitlement only unlocks the Pro tier.
            if ($app->slug === 'hpp') {
                [$status, $allowed] = ['active', true];
            }

            $action = $allowed ? 'open' : 'upgrade';

            return [
                'app' => [
                    'slug' => $app->slug,
                    'name' => $app->name,
                    'icon_url' => $app->icon_url,
                ],
                'entitlement' => [
                    'status' => $status,
                    'allowed' => $allowed,
                    'plan' => $entitlement?->plan ? [
                        'slug' => (string) $entitlement->plan->slug,
                        'name' => (string) $entitlement->plan->name,
                        'type' => (string) $entitlement->plan->type,
                        'price' => (int) $entitlement->plan->price,
                    ] : null,
                ],
                'card' => [
                    'badge' => $allowed ? 'Active' : 'Locked',
                    'action' => $action,
                    'action_label' => $allowed ? 'Open App' : 'Upgrade',
                ],
                'access' => $app->slug === 'hpp' ? ['dashboard_path' => '/dashboard/apps/hpp'] : ($app->slug === 'pos' ? [
                    'dashboard_path' => '/dashboard/apps/pos',
                    'legacy_admin_path' => '/admin',
                    'legacy_cashier_path' => '/cashier/login',
                    'legacy_customer_path' => '/pos',
                ] : null),
            ];
        })->values();

        $promotedSlugs = json_decode((string) SystemSetting::get('dashboard_promoted_app_slugs', '[]'), true);
        $selectedAppSlugs = is_array($promotedSlugs) && array_is_list($promotedSlugs)
            ? $promotedSlugs
            : (is_array($promotedSlugs) ? ($promotedSlugs['apps'] ?? []) : []);
        $selectedProductIds = is_array($promotedSlugs) && !array_is_list($promotedSlugs)
            ? array_map('intval', $promotedSlugs['products'] ?? [])
            : [];
        $selectedAppSlugs = array_values(array_unique(array_filter($selectedAppSlugs, 'is_string')));

        $ownedFlagshipSlugs = DigitalProduct::query()
            ->where('is_flagship', true)
            ->where('type', 'paid')
            ->where('price', '>', 0)
            ->whereIn('flagship_app', $selectedAppSlugs)
            ->whereHas('purchases', fn ($query) => $query->where('user_id', $user->id)->where('payment_status', 'paid')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', now())))
            ->pluck('flagship_app')
            ->all();
        $promotedCards = $cards
            ->filter(fn (array $card) => in_array($card['app']['slug'], $selectedAppSlugs, true)
                && !$card['entitlement']['allowed']
                && !in_array($card['app']['slug'], $ownedFlagshipSlugs, true))
            ->values();

        if ($selectedProductIds !== []) {
            $products = DigitalProduct::query()
                ->published()
                ->where('is_flagship', true)
                ->where('type', 'paid')
                ->where('price', '>', 0)
                ->whereIn('id', $selectedProductIds)
                ->with(['purchases' => fn ($query) => $query->where('user_id', $user->id)->where('payment_status', 'paid')])
                ->orderBy('sort_order')
                ->get();

            $promotedCards = $promotedCards->concat($products
                ->reject(fn (DigitalProduct $product) => $product->purchases->contains(fn (ProductPurchase $purchase) => $purchase->hasAccess()))
                ->map(fn (DigitalProduct $product) => [
                    'app' => [
                        'slug' => 'product-' . $product->id,
                        'name' => $product->name,
                        'icon_url' => $product->thumbnail_url,
                    ],
                    'entitlement' => ['status' => 'locked', 'allowed' => false, 'plan' => null],
                    'card' => ['badge' => 'Sekali beli', 'action' => 'purchase', 'action_label' => 'Beli sekali'],
                    'promotion' => [
                        'type' => 'one_time_product',
                        'slug' => $product->slug,
                        'price' => (int) $product->price,
                        'target' => '/dashboard/products/' . $product->slug . '/checkout',
                    ],
                ]));
        }

        return $this->ok([
            'organization_id' => $organizationId,
            'cards' => $cards,
            'promoted_cards' => $promotedCards,
        ], 'Member dashboard cards');
    }
}
