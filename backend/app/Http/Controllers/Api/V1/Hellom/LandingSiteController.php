<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Api\V1\Hellom\Concerns\ResolvesSellerOrganization;
use App\Models\LandingPageVersion;
use App\Models\LandingProduct;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Services\Landing\LandingDocumentService;
use App\Services\Landing\LandingShop;
use App\Support\Landing\BlockSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Hellom Page editor API (Fase 4): shop username, pages, draft document (autosave),
 * publish, version history, preview link. Owner/admin of the shop only (audit LB-24).
 */
class LandingSiteController extends BaseApiController
{
    use ResolvesSellerOrganization;

    public function __construct(
        private readonly LandingShop $shop,
        private readonly LandingDocumentService $documents,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }

        return $this->ok($this->sitePayload($organization), 'Halaman toko');
    }

    public function updateUsername(Request $request): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $validated = $request->validate(['username' => ['required', 'string', 'max:40']]);
        $username = strtolower(trim($validated['username']));
        if ($username !== $organization->landingUsername() && ($problem = $this->shop->usernameProblem($username, (int) $organization->id))) {
            throw ValidationException::withMessages(['username' => $problem]);
        }
        $this->shop->changeUsername($organization, $username);

        return $this->ok($this->sitePayload($organization->fresh()), 'Username disimpan. Link lama otomatis diarahkan ke username baru.');
    }

    public function createPage(Request $request): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:60'],
            'document' => ['nullable', 'array'],
        ]);
        $count = OrganizationLandingPage::query()->where('organization_id', $organization->id)->count();
        $quota = $this->shop->pageQuota($organization);
        if ($count >= $quota) {
            return $this->fail("Paket kamu bisa punya {$quota} halaman. Upgrade paket Hellom Page untuk menambah halaman.", ['code' => 'PAGE_QUOTA', 'quota' => $quota], 422);
        }
        $slug = $this->uniquePageSlug($organization, (string) ($validated['slug'] ?? '') ?: (string) $validated['title']);
        $page = OrganizationLandingPage::query()->create([
            'organization_id' => $organization->id,
            'title' => trim($validated['title']),
            'slug' => $slug,
            'status' => 'draft',
            'content' => [],
            'is_home' => $count === 0,
        ]);
        $page->forceFill(['draft_document' => BlockSchema::normalize($validated['document'] ?? []), 'draft_saved_at' => now()])->save();

        return $this->ok($this->pagePayload($page->fresh(), $organization), 'Halaman dibuat', 201);
    }

    public function updatePage(Request $request, int $pageId): JsonResponse
    {
        [$page, $organization, $error] = $this->page($request, $pageId);
        if ($error) {
            return $error;
        }
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:60'],
            'is_home' => ['sometimes', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:120'],
            'seo_description' => ['nullable', 'string', 'max:300'],
            'seo_image' => ['nullable', 'string', 'max:500'],
        ]);
        if (isset($validated['slug'])) {
            $slug = Str::slug($validated['slug']);
            if ($slug === '' || $this->slugTaken($organization, $slug, $page->id)) {
                throw ValidationException::withMessages(['slug' => 'Alamat halaman sudah dipakai halaman atau produk lain.']);
            }
            $validated['slug'] = $slug;
        }
        if (array_key_exists('seo_image', $validated) && $validated['seo_image'] !== null) {
            $validated['seo_image'] = BlockSchema::url($validated['seo_image'], true);
        }
        DB::transaction(function () use ($page, $organization, $validated): void {
            if (!empty($validated['is_home'])) {
                OrganizationLandingPage::query()->where('organization_id', $organization->id)->update(['is_home' => false]);
            }
            $page->forceFill($validated)->save();
        });
        $this->shop->bumpCache((int) $organization->id);

        return $this->ok($this->pagePayload($page->fresh(), $organization), 'Halaman disimpan');
    }

    public function deletePage(Request $request, int $pageId): JsonResponse
    {
        [$page, $organization, $error] = $this->page($request, $pageId);
        if ($error) {
            return $error;
        }
        DB::transaction(function () use ($page, $organization): void {
            $wasHome = $page->is_home;
            $page->delete();
            if ($wasHome) {
                OrganizationLandingPage::query()->where('organization_id', $organization->id)->orderBy('id')->limit(1)->update(['is_home' => true]);
            }
        });
        $this->shop->bumpCache((int) $organization->id);

        return $this->ok(['deleted' => true], 'Halaman dihapus');
    }

    public function document(Request $request, int $pageId): JsonResponse
    {
        [$page, , $error] = $this->page($request, $pageId);

        return $error ?? $this->ok($this->documents->draft($page) + ['page' => $this->pagePayload($page, $page->organization)], 'Draft halaman');
    }

    /** Autosave. Body: { document, revision }. */
    public function saveDocument(Request $request, int $pageId): JsonResponse
    {
        [$page, , $error] = $this->page($request, $pageId);
        if ($error) {
            return $error;
        }
        $validated = $request->validate(['document' => ['required', 'array'], 'revision' => ['nullable', 'integer', 'min:0']]);
        try {
            $saved = $this->documents->saveDraft($page, $validated['document'], isset($validated['revision']) ? (int) $validated['revision'] : null);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'DRAFT_CONFLICT') {
                return $this->fail('Halaman ini baru diubah dari tab/perangkat lain. Muat ulang untuk melihat versi terbaru.', ['code' => 'DRAFT_CONFLICT'], 409);
            }
            throw $e;
        }

        return $this->ok($saved, 'Draft tersimpan');
    }

    public function publish(Request $request, int $pageId): JsonResponse
    {
        [$page, $organization, $error] = $this->page($request, $pageId);
        if ($error) {
            return $error;
        }
        if ($organization->landing_suspended_at !== null) {
            return $this->fail('Toko kamu sedang dinonaktifkan tim Hellom.', ['code' => 'SELLER_SUSPENDED'], 403);
        }
        try {
            $version = $this->documents->publish($page, $request->user());
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'PAGE_QUOTA') {
                $quota = $this->shop->pageQuota($organization);

                return $this->fail("Paket kamu bisa menerbitkan {$quota} halaman. Upgrade paket Hellom Page untuk menerbitkan lebih banyak.", ['code' => 'PAGE_QUOTA', 'quota' => $quota], 422);
            }
            throw $e;
        }

        return $this->ok(['version_no' => $version->version_no, 'page' => $this->pagePayload($page->fresh(), $organization)], 'Halaman diterbitkan');
    }

    public function unpublish(Request $request, int $pageId): JsonResponse
    {
        [$page, $organization, $error] = $this->page($request, $pageId);
        if ($error) {
            return $error;
        }
        $this->documents->unpublish($page);

        return $this->ok(['page' => $this->pagePayload($page->fresh(), $organization)], 'Halaman disembunyikan dari publik');
    }

    public function history(Request $request, int $pageId): JsonResponse
    {
        [$page, , $error] = $this->page($request, $pageId);
        if ($error) {
            return $error;
        }
        $items = LandingPageVersion::query()->where('landing_page_id', $page->id)->orderByDesc('version_no')->limit(30)
            ->get(['id', 'version_no', 'published_at', 'created_by_user_id', 'document'])
            ->map(fn (LandingPageVersion $v) => [
                'id' => $v->id,
                'version_no' => $v->version_no,
                'published_at' => optional($v->published_at)->toIso8601String(),
                'blocks' => is_array($v->document) ? count($v->document['blocks'] ?? []) : null,
                'is_live' => $v->id === $page->published_version_id,
            ]);

        return $this->ok(['items' => $items], 'Riwayat terbit');
    }

    public function restore(Request $request, int $pageId, int $versionId): JsonResponse
    {
        [$page, , $error] = $this->page($request, $pageId);
        if ($error) {
            return $error;
        }
        $version = LandingPageVersion::query()->where('landing_page_id', $page->id)->find($versionId);
        if (!$version) {
            return $this->fail('Versi tidak ditemukan', ['code' => 'VERSION_NOT_FOUND'], 404);
        }

        return $this->ok($this->documents->restore($page, $version), 'Versi ' . $version->version_no . ' dikembalikan ke draft. Terbitkan untuk menayangkannya.');
    }

    /** Short-lived signed URL of the server-rendered draft (editor preview iframe). */
    public function previewLink(Request $request, int $pageId): JsonResponse
    {
        [$page, , $error] = $this->page($request, $pageId);
        if ($error) {
            return $error;
        }

        return $this->ok(['url' => URL::temporarySignedRoute('landing.preview', now()->addMinutes(30), ['page' => $page->id])], 'Link pratinjau');
    }

    /** @return array{0: ?OrganizationLandingPage, 1: ?Organization, 2: ?JsonResponse} */
    private function page(Request $request, int $pageId): array
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return [null, null, $error];
        }
        $page = OrganizationLandingPage::query()->where('organization_id', $organization->id)->with('organization')->find($pageId);

        return $page ? [$page, $organization, null] : [null, $organization, $this->fail('Halaman tidak ditemukan', ['code' => 'PAGE_NOT_FOUND'], 404)];
    }

    private function slugTaken(Organization $organization, string $slug, ?int $exceptPageId = null): bool
    {
        return OrganizationLandingPage::query()->where('organization_id', $organization->id)->where('slug', $slug)
            ->when($exceptPageId, fn ($q) => $q->whereKeyNot($exceptPageId))->exists()
            || LandingProduct::withTrashed()->where('organization_id', $organization->id)->where('slug', $slug)->exists();
    }

    private function uniquePageSlug(Organization $organization, string $source): string
    {
        $base = Str::limit(Str::slug($source) ?: 'halaman', 50, '');
        $slug = $base;
        for ($i = 2; $this->slugTaken($organization, $slug); $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function sitePayload(Organization $organization): array
    {
        $pages = OrganizationLandingPage::query()->where('organization_id', $organization->id)->orderByDesc('is_home')->orderBy('id')->get();
        $live = $this->shop->livePages($organization)->pluck('id')->all();

        return [
            'username' => $organization->landingUsername(),
            'username_is_custom' => $organization->landing_username !== null,
            'public_url' => $this->shop->publicUrl($organization),
            'suspended' => $organization->landing_suspended_at !== null,
            'quota' => ['pages' => $this->shop->pageQuota($organization), 'used' => $pages->count(), 'free' => (int) config('landing.free_pages', 1)],
            'pages' => $pages->map(fn (OrganizationLandingPage $p) => $this->pagePayload($p, $organization, in_array($p->id, $live, true)))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function pagePayload(OrganizationLandingPage $page, Organization $organization, ?bool $live = null): array
    {
        $live ??= in_array($page->id, $this->shop->livePages($organization)->pluck('id')->all(), true);

        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'status' => $page->status,
            'is_home' => (bool) $page->is_home,
            'is_live' => $live,
            'url' => $this->shop->publicUrl($organization, $page->is_home ? '' : (string) $page->slug),
            'published_at' => optional($page->published_at)->toIso8601String(),
            'draft_saved_at' => optional($page->draft_saved_at)->toIso8601String(),
            'has_unpublished_changes' => $page->draft_saved_at !== null && ($page->published_at === null || $page->draft_saved_at->greaterThan($page->published_at)),
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'seo_image' => $page->seo_image,
        ];
    }
}
