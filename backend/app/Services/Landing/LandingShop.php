<?php

namespace App\Services\Landing;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Models\Plan;
use App\Support\FrontendUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Shop-level rules of Hellom Page: public username, page quota (Q3: 1 free page, more with
 * a paid plan whose `max_landing_pages` the super admin sets), which pages are live, and
 * the cache version that invalidates the server-rendered pages.
 */
final class LandingShop
{
    public function isReservedUsername(string $username): bool
    {
        return in_array(strtolower($username), config('landing.reserved_usernames', []), true);
    }

    /** Why this username cannot be used, or null. */
    public function usernameProblem(string $username, ?int $exceptOrganizationId = null): ?string
    {
        if (!preg_match((string) config('landing.username_pattern'), $username)) {
            return 'Username 3–30 karakter: huruf kecil, angka, atau tanda minus (tidak di awal/akhir).';
        }
        if ($this->isReservedUsername($username)) {
            return 'Username ini tidak bisa dipakai. Coba yang lain.';
        }
        $taken = Organization::query()
            ->where(fn ($q) => $q->where('landing_username', $username)->orWhere(fn ($w) => $w->whereNull('landing_username')->where('slug', $username)))
            ->when($exceptOrganizationId, fn ($q) => $q->whereKeyNot($exceptOrganizationId))
            ->exists()
            // An old username of another shop still redirects there, so it stays taken.
            || DB::table('landing_username_redirects')->where('username', $username)
                ->when($exceptOrganizationId, fn ($q) => $q->where('organization_id', '!=', $exceptOrganizationId))->exists();

        return $taken ? 'Username sudah dipakai toko lain.' : null;
    }

    /** Set a new username; the previous address keeps redirecting to the shop. */
    public function changeUsername(Organization $organization, string $username): void
    {
        DB::transaction(function () use ($organization, $username): void {
            $old = $organization->landingUsername();
            if ($old !== $username) {
                DB::table('landing_username_redirects')->updateOrInsert(['username' => $old], ['organization_id' => $organization->id, 'updated_at' => now(), 'created_at' => now()]);
            }
            DB::table('landing_username_redirects')->where('username', $username)->where('organization_id', $organization->id)->delete();
            $organization->forceFill(['landing_username' => $username])->save();
        });
        $this->bumpCache((int) $organization->id);
    }

    /** Shop that used to live at this address (after a username change). */
    public function redirectTarget(string $username): ?Organization
    {
        $id = DB::table('landing_username_redirects')->where('username', strtolower($username))->value('organization_id');
        if ($id) {
            return Organization::query()->find($id);
        }

        return Organization::query()->where('slug', strtolower($username))->whereNotNull('landing_username')->first();
    }

    /** Shop behind a public username (landing_username first, then the org slug for shops that never set one). */
    public function findByUsername(string $username): ?Organization
    {
        $username = strtolower($username);
        if ($this->isReservedUsername($username)) {
            return null;
        }

        return Organization::query()->where('landing_username', $username)->first()
            ?? Organization::query()->whereNull('landing_username')->where('slug', $username)->first();
    }

    /** Pages the shop may publish right now. */
    public function pageQuota(Organization|int $organization): int
    {
        $organizationId = $organization instanceof Organization ? (int) $organization->id : $organization;
        $free = max(1, (int) config('landing.free_pages', 1));
        $appId = AppCatalog::query()->where('slug', 'landing_builder')->value('id');
        if (!$appId) {
            return $free;
        }
        $entitlement = Entitlement::query()->where('organization_id', $organizationId)->where('app_id', $appId)
            ->orderByDesc('updated_at')->orderByDesc('id')->first();
        if (!$entitlement || !$entitlement->allowsAccess() || !$entitlement->plan_id) {
            return $free;
        }
        $planMax = Plan::query()->whereKey($entitlement->plan_id)->value('max_landing_pages');

        return max($free, (int) ($planMax ?? $free));
    }

    /**
     * Published pages that are live, home page first; pages beyond the quota (e.g. after a
     * paid plan ended) stay published in the editor but are not served.
     *
     * @return Collection<int, OrganizationLandingPage>
     */
    public function livePages(Organization $organization): Collection
    {
        return OrganizationLandingPage::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'published')
            ->whereNotNull('published_version_id')
            ->orderByDesc('is_home')->orderBy('id')
            ->limit($this->pageQuota($organization))
            ->get();
    }

    public function cacheVersion(int $organizationId): int
    {
        return (int) Cache::get("landing:cachever:{$organizationId}", 1);
    }

    /** Invalidate every cached public page of the shop (publish, product change, settings). */
    public function bumpCache(int $organizationId): void
    {
        Cache::forever("landing:cachever:{$organizationId}", $this->cacheVersion($organizationId) + 1);
    }

    public function publicUrl(Organization $organization, string $path = ''): string
    {
        return FrontendUrl::to('/' . $organization->landingUsername() . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}
