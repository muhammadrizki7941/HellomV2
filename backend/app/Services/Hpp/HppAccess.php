<?php

namespace App\Services\Hpp;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Plan;

/**
 * Kalkulator HPP tiers: Cepat / Resep (Tahap 1–3) are free for every shop. Pro (Tahap 4: variance,
 * simulasi, FIFO, harga per outlet, ekspor) is open when the shop has an active POS Kasir
 * subscription (included for free) or its own "hpp" subscription (plans hpp_*).
 */
final class HppAccess
{
    /** @return array{active: bool, source: ?string, plans: list<array<string, mixed>>} */
    public static function pro(int $organizationId): array
    {
        $source = null;
        foreach (['pos' => 'pos', 'hpp' => 'hpp'] as $slug => $label) {
            if (self::entitled($organizationId, $slug)) {
                $source = $label;
                break;
            }
        }

        return [
            'active' => $source !== null,
            'source' => $source,
            'plans' => $source ? [] : Plan::query()->where('slug', 'like', 'hpp\_%')->where('is_active', true)->where('is_visible', true)
                ->orderBy('sort_order')->get(['slug', 'name', 'price', 'billing_cycles'])
                ->map(fn (Plan $p) => ['slug' => (string) $p->slug, 'name' => (string) $p->name, 'price' => (int) $p->price, 'billing_cycles' => (array) ($p->billing_cycles ?? [])])
                ->values()->all(),
        ];
    }

    public static function isPro(int $organizationId): bool
    {
        return self::entitled($organizationId, 'pos') || self::entitled($organizationId, 'hpp');
    }

    private static function entitled(int $organizationId, string $appSlug): bool
    {
        $appId = AppCatalog::query()->where('slug', $appSlug)->value('id');
        if (!$appId) {
            return false;
        }
        $entitlement = Entitlement::query()->where('organization_id', $organizationId)->where('app_id', $appId)->latest('updated_at')->first();

        return (bool) $entitlement?->allowsAccess();
    }
}
