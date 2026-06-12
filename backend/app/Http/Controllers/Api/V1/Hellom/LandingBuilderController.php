<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\LandingDomain;
use App\Models\LandingBlock;
use App\Models\LandingPageStat;
use App\Models\LandingPageVersion;
use App\Models\LandingStat;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Models\CustomerLandingpage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class LandingBuilderController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $pages = OrganizationLandingPage::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get()
            ->map(fn(OrganizationLandingPage $page) => $this->pagePayload($page))
            ->values();

        return $this->ok(['items' => $pages], 'Landing pages');
    }

    public function store(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:160'],
            'content' => ['nullable', 'array'],
        ]);

        $slug = $this->uniqueSlug(
            $organizationId,
            (string) ($validated['slug'] ?? $validated['title'])
        );

        $page = OrganizationLandingPage::query()->create([
            'organization_id' => $organizationId,
            'title' => $validated['title'],
            'slug' => $slug,
            'status' => 'draft',
            'content' => $validated['content'] ?? null,
            'published_at' => null,
        ]);

        $this->snapshotVersion($page, 'draft');

        return $this->ok($this->pagePayload($page), 'Landing page created', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        return $this->ok($this->pagePayload($page), 'Landing page detail');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:160'],
            'content' => ['sometimes', 'nullable', 'array'],
        ]);

        if (array_key_exists('title', $validated)) {
            $page->title = $validated['title'];
        }

        if (array_key_exists('slug', $validated) || array_key_exists('title', $validated)) {
            $seed = array_key_exists('slug', $validated)
                ? (string) ($validated['slug'] ?: $page->title)
                : (string) $page->title;

            $page->slug = $this->uniqueSlug((int) $page->organization_id, $seed, (int) $page->id);
        }

        if (array_key_exists('content', $validated)) {
            $page->content = $validated['content'];
        }

        $page->save();
        $this->snapshotVersion($page, 'draft');

        return $this->ok($this->pagePayload($page), 'Landing page updated');
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $page->forceFill([
            'status' => 'published',
            'published_at' => now(),
        ])->save();

        $this->snapshotVersion($page, 'published');
        $this->trackPublishActivation($page);

        return $this->ok($this->pagePayload($page), 'Landing page published');
    }

    public function versions(Request $request, int $id): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $versions = LandingPageVersion::query()
            ->where('landing_page_id', (int) $page->id)
            ->orderByDesc('version_no')
            ->get()
            ->map(fn(LandingPageVersion $version) => $this->versionPayload($version))
            ->values();

        return $this->ok([
            'page_id' => (int) $page->id,
            'items' => $versions,
        ], 'Landing page versions');
    }

    public function restoreVersion(Request $request, int $id, int $versionId): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $version = LandingPageVersion::query()
            ->where('landing_page_id', (int) $page->id)
            ->where('id', $versionId)
            ->first();

        if (!$version) {
            return $this->fail('Landing page version not found', ['code' => 'LANDING_PAGE_VERSION_NOT_FOUND'], 404);
        }

        $page->forceFill([
            'title' => $version->title,
            'slug' => $this->uniqueSlug((int) $page->organization_id, (string) $version->slug, (int) $page->id),
            'content' => $version->content,
            'status' => 'draft',
            'published_at' => null,
        ])->save();

        $snapshot = $this->snapshotVersion($page, 'draft');

        return $this->ok([
            'page' => $this->pagePayload($page),
            'restored_from' => $this->versionPayload($version),
            'new_version' => $this->versionPayload($snapshot),
        ], 'Landing page version restored');
    }

    public function stats(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $stat = LandingStat::query()
            ->with('firstPublishedPage:id,title,slug')
            ->where('organization_id', $organizationId)
            ->first();

        if (!$stat) {
            return $this->ok([
                'organization_id' => $organizationId,
                'published_count' => 0,
                'views_count' => 0,
                'last_viewed_at' => null,
                'first_published_at' => null,
                'first_published_page' => null,
            ], 'Landing builder stats');
        }

        return $this->ok([
            'organization_id' => (int) $stat->organization_id,
            'published_count' => (int) $stat->published_count,
            'views_count' => (int) ($stat->views_count ?? 0),
            'last_viewed_at' => $stat->last_viewed_at,
            'first_published_at' => $stat->first_published_at,
            'first_published_page' => $stat->firstPublishedPage ? [
                'id' => (int) $stat->firstPublishedPage->id,
                'title' => (string) $stat->firstPublishedPage->title,
                'slug' => (string) $stat->firstPublishedPage->slug,
            ] : null,
        ], 'Landing builder stats');
    }

    public function pageStats(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $items = LandingPageStat::query()
            ->with('landingPage:id,title,slug,status,published_at')
            ->where('organization_id', $organizationId)
            ->orderByDesc('views_count')
            ->orderByDesc('id')
            ->get()
            ->map(function (LandingPageStat $stat): array {
                return [
                    'landing_page' => $stat->landingPage ? [
                        'id' => (int) $stat->landingPage->id,
                        'title' => (string) $stat->landingPage->title,
                        'slug' => (string) $stat->landingPage->slug,
                        'status' => (string) $stat->landingPage->status,
                        'published_at' => $stat->landingPage->published_at,
                    ] : null,
                    'views_count' => (int) $stat->views_count,
                    'last_viewed_at' => $stat->last_viewed_at,
                ];
            })
            ->values();

        return $this->ok([
            'organization_id' => $organizationId,
            'items' => $items,
        ], 'Landing page stats');
    }

    public function funnelKpi(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $organization = Organization::query()->find($organizationId);
        if (!$organization) {
            return $this->fail('Organization not found', ['code' => 'ORGANIZATION_NOT_FOUND'], 404);
        }

        $stat = LandingStat::query()
            ->where('organization_id', $organizationId)
            ->first();

        $createdAt = $organization->created_at;
        $firstPublishedAt = $stat?->first_published_at;
        $daysToFirstPublish = null;
        if ($createdAt && $firstPublishedAt) {
            $daysToFirstPublish = (int) $createdAt->diffInDays($firstPublishedAt);
        }

        $publishedCount = (int) ($stat?->published_count ?? 0);
        $viewsCount = (int) ($stat?->views_count ?? 0);

        return $this->ok([
            'organization_id' => $organizationId,
            'funnel' => [
                'organization_created_at' => $createdAt,
                'first_published_at' => $firstPublishedAt,
                'days_to_first_publish' => $daysToFirstPublish,
                'published_count' => $publishedCount,
                'views_count' => $viewsCount,
                'has_published' => $firstPublishedAt !== null,
            ],
        ], 'Landing funnel KPI');
    }

    public function performanceSummary(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $totalPages = (int) OrganizationLandingPage::query()
            ->where('organization_id', $organizationId)
            ->count();

        $totalViews = (int) LandingPageStat::query()
            ->where('organization_id', $organizationId)
            ->sum('views_count');

        $topStat = LandingPageStat::query()
            ->with('landingPage:id,title,slug,status')
            ->where('organization_id', $organizationId)
            ->orderByDesc('views_count')
            ->orderByDesc('id')
            ->first();

        $avgViewsPerPage = $totalPages > 0
            ? round($totalViews / $totalPages, 2)
            : 0.0;

        return $this->ok([
            'organization_id' => $organizationId,
            'summary' => [
                'total_pages' => $totalPages,
                'total_views' => $totalViews,
                'average_views_per_page' => $avgViewsPerPage,
                'top_page' => $topStat && $topStat->landingPage ? [
                    'id' => (int) $topStat->landingPage->id,
                    'title' => (string) $topStat->landingPage->title,
                    'slug' => (string) $topStat->landingPage->slug,
                    'status' => (string) $topStat->landingPage->status,
                    'views_count' => (int) $topStat->views_count,
                ] : null,
            ],
        ], 'Landing performance summary');
    }

    public function customers(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $pageId = (int) $request->query('page_id', 0);

        $items = CustomerLandingpage::query()
            ->with('landingPage:id,title,slug')
            ->where('organization_id', $organizationId)
            ->when($pageId > 0, fn($query) => $query->where('landing_page_id', $pageId))
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn(CustomerLandingpage $customer) => $this->customerPayload($customer))
            ->values();

        return $this->ok([
            'organization_id' => $organizationId,
            'items' => $items,
        ], 'Landing page customers');
    }

    public function domains(Request $request, int $id): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $items = LandingDomain::query()
            ->where('landing_page_id', (int) $page->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->map(fn(LandingDomain $domain) => $this->domainPayload($domain))
            ->values();

        return $this->ok([
            'page_id' => (int) $page->id,
            'items' => $items,
        ], 'Landing domains');
    }

    public function blocks(Request $request, int $id): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $items = LandingBlock::query()
            ->where('landing_page_id', (int) $page->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn(LandingBlock $block) => $this->blockPayload($block))
            ->values();

        return $this->ok([
            'page_id' => (int) $page->id,
            'items' => $items,
        ], 'Landing blocks');
    }

    public function storeBlock(Request $request, int $id): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'block_key' => ['required', 'string', 'max:120'],
            'block_type' => ['required', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_visible' => ['nullable', 'boolean'],
            'content' => ['nullable', 'array'],
        ]);

        $nextSort = (int) LandingBlock::query()
            ->where('landing_page_id', (int) $page->id)
            ->max('sort_order') + 1;

        $block = LandingBlock::query()->create([
            'organization_id' => (int) $page->organization_id,
            'landing_page_id' => (int) $page->id,
            'block_key' => (string) $validated['block_key'],
            'block_type' => (string) $validated['block_type'],
            'sort_order' => array_key_exists('sort_order', $validated) ? (int) $validated['sort_order'] : $nextSort,
            'is_visible' => (bool) ($validated['is_visible'] ?? true),
            'content' => $validated['content'] ?? null,
        ]);

        return $this->ok($this->blockPayload($block), 'Landing block created', 201);
    }

    public function updateBlock(Request $request, int $id, int $blockId): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $block = LandingBlock::query()
            ->where('landing_page_id', (int) $page->id)
            ->where('id', $blockId)
            ->first();

        if (!$block) {
            return $this->fail('Landing block not found', ['code' => 'LANDING_BLOCK_NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'block_key' => ['sometimes', 'required', 'string', 'max:120'],
            'block_type' => ['sometimes', 'required', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_visible' => ['sometimes', 'boolean'],
            'content' => ['sometimes', 'nullable', 'array'],
        ]);

        foreach (['block_key', 'block_type', 'sort_order', 'is_visible', 'content'] as $field) {
            if (array_key_exists($field, $validated)) {
                $block->{$field} = $validated[$field];
            }
        }

        $block->save();

        return $this->ok($this->blockPayload($block), 'Landing block updated');
    }

    public function destroyBlock(Request $request, int $id, int $blockId): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $block = LandingBlock::query()
            ->where('landing_page_id', (int) $page->id)
            ->where('id', $blockId)
            ->first();

        if (!$block) {
            return $this->fail('Landing block not found', ['code' => 'LANDING_BLOCK_NOT_FOUND'], 404);
        }

        $block->delete();

        return $this->ok([
            'id' => $blockId,
            'deleted' => true,
        ], 'Landing block deleted');
    }

    public function reorderBlocks(Request $request, int $id): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'orders' => ['required', 'array', 'min:1'],
            'orders.*.id' => ['required', 'integer'],
            'orders.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $orders = (array) $validated['orders'];
        $blockIds = collect($orders)->pluck('id')->map(fn($id) => (int) $id)->values();

        $existingCount = LandingBlock::query()
            ->where('landing_page_id', (int) $page->id)
            ->whereIn('id', $blockIds)
            ->count();

        if ($existingCount !== $blockIds->count()) {
            return $this->fail('One or more blocks not found for this page', ['code' => 'LANDING_BLOCK_NOT_FOUND'], 404);
        }

        DB::transaction(function () use ($orders, $page): void {
            foreach ($orders as $item) {
                LandingBlock::query()
                    ->where('landing_page_id', (int) $page->id)
                    ->where('id', (int) $item['id'])
                    ->update(['sort_order' => (int) $item['sort_order']]);
            }
        });

        $items = LandingBlock::query()
            ->where('landing_page_id', (int) $page->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn(LandingBlock $block) => $this->blockPayload($block))
            ->values();

        return $this->ok([
            'page_id' => (int) $page->id,
            'items' => $items,
        ], 'Landing blocks reordered');
    }

    public function storeDomain(Request $request, int $id): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
            'set_primary' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:pending,verified,failed'],
        ]);

        $normalizedDomain = $this->normalizeDomain((string) $validated['domain']);
        if ($normalizedDomain === '') {
            return $this->fail('Invalid domain format', ['code' => 'INVALID_DOMAIN'], 422);
        }

        $isPrimary = (bool) ($validated['set_primary'] ?? false);
        $status = (string) ($validated['status'] ?? 'pending');

        if ($isPrimary) {
            LandingDomain::query()
                ->where('landing_page_id', (int) $page->id)
                ->update(['is_primary' => false]);
        }

        $domain = LandingDomain::query()->create([
            'organization_id' => (int) $page->organization_id,
            'landing_page_id' => (int) $page->id,
            'domain' => $normalizedDomain,
            'is_primary' => $isPrimary,
            'status' => $status,
            'verified_at' => $status === 'verified' ? now() : null,
        ]);

        return $this->ok($this->domainPayload($domain), 'Landing domain created', 201);
    }

    public function updateDomain(Request $request, int $id, int $domainId): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $domain = LandingDomain::query()
            ->where('landing_page_id', (int) $page->id)
            ->where('id', $domainId)
            ->first();

        if (!$domain) {
            return $this->fail('Landing domain not found', ['code' => 'LANDING_DOMAIN_NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'required', 'in:pending,verified,failed'],
            'set_primary' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('status', $validated)) {
            $domain->status = (string) $validated['status'];
            $domain->verified_at = $domain->status === 'verified' ? now() : null;
        }

        if (array_key_exists('set_primary', $validated) && (bool) $validated['set_primary'] === true) {
            LandingDomain::query()
                ->where('landing_page_id', (int) $page->id)
                ->update(['is_primary' => false]);
            $domain->is_primary = true;
        }

        $domain->save();

        return $this->ok($this->domainPayload($domain), 'Landing domain updated');
    }

    public function destroyDomain(Request $request, int $id, int $domainId): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $domain = LandingDomain::query()
            ->where('landing_page_id', (int) $page->id)
            ->where('id', $domainId)
            ->first();

        if (!$domain) {
            return $this->fail('Landing domain not found', ['code' => 'LANDING_DOMAIN_NOT_FOUND'], 404);
        }

        $domain->delete();

        return $this->ok([
            'id' => $domainId,
            'deleted' => true,
        ], 'Landing domain deleted');
    }

    public function unpublish(Request $request, int $id): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $page->forceFill([
            'status' => 'draft',
            'published_at' => null,
        ])->save();

        return $this->ok($this->pagePayload($page), 'Landing page unpublished');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $deletedId = (int) $page->id;
        $deletedSlug = (string) $page->slug;
        $page->delete();

        return $this->ok([
            'id' => $deletedId,
            'slug' => $deletedSlug,
            'deleted' => true,
        ], 'Landing page deleted');
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $duplicate = DB::transaction(function () use ($page): OrganizationLandingPage {
            $newTitle = (string) $page->title . ' Copy';
            $newSlug = $this->uniqueSlug((int) $page->organization_id, (string) $page->slug . '-copy');

            $cloned = OrganizationLandingPage::query()->create([
                'organization_id' => (int) $page->organization_id,
                'title' => $newTitle,
                'slug' => $newSlug,
                'status' => 'draft',
                'content' => $page->content,
                'published_at' => null,
            ]);

            $blocks = LandingBlock::query()
                ->where('landing_page_id', (int) $page->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            foreach ($blocks as $block) {
                LandingBlock::query()->create([
                    'organization_id' => (int) $cloned->organization_id,
                    'landing_page_id' => (int) $cloned->id,
                    'block_key' => (string) $block->block_key,
                    'block_type' => (string) $block->block_type,
                    'sort_order' => (int) $block->sort_order,
                    'is_visible' => (bool) $block->is_visible,
                    'content' => $block->content,
                ]);
            }

            $this->snapshotVersion($cloned, 'draft');

            return $cloned;
        });

        return $this->ok($this->pagePayload($duplicate), 'Landing page duplicated', 201);
    }

    public function templates(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $items = collect($this->landingTemplates())
            ->map(fn(array $template): array => [
                'key' => (string) $template['key'],
                'name' => (string) $template['name'],
                'description' => (string) $template['description'],
                'preview' => [
                    'hero_title' => (string) data_get($template, 'content.hero.title', ''),
                    'block_count' => count((array) ($template['blocks'] ?? [])),
                ],
            ])
            ->values();

        return $this->ok(['items' => $items], 'Landing templates');
    }

    public function templateDetail(Request $request, string $key): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $template = collect($this->landingTemplates())
            ->first(fn(array $row): bool => (string) $row['key'] === $key);

        if (!$template) {
            return $this->fail('Landing template not found', ['code' => 'LANDING_TEMPLATE_NOT_FOUND'], 404);
        }

        return $this->ok([
            'key' => (string) $template['key'],
            'name' => (string) $template['name'],
            'description' => (string) $template['description'],
            'content' => $template['content'] ?? null,
            'blocks' => collect((array) ($template['blocks'] ?? []))
                ->map(fn(array $block): array => [
                    'block_key' => (string) ($block['block_key'] ?? ''),
                    'block_type' => (string) ($block['block_type'] ?? ''),
                    'sort_order' => (int) ($block['sort_order'] ?? 0),
                    'is_visible' => (bool) ($block['is_visible'] ?? true),
                    'content' => $block['content'] ?? null,
                ])
                ->values(),
        ], 'Landing template detail');
    }

    public function templateBlockKeys(Request $request, string $key): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $template = collect($this->landingTemplates())
            ->first(fn(array $row): bool => (string) $row['key'] === $key);

        if (!$template) {
            return $this->fail('Landing template not found', ['code' => 'LANDING_TEMPLATE_NOT_FOUND'], 404);
        }

        $items = collect((array) ($template['blocks'] ?? []))
            ->values()
            ->map(fn(array $block, int $idx): array => [
                'block_key' => (string) ($block['block_key'] ?? ('block_' . ($idx + 1))),
                'block_type' => (string) ($block['block_type'] ?? 'section'),
                'sort_order' => (int) ($block['sort_order'] ?? ($idx + 1)),
            ])
            ->values();

        return $this->ok([
            'template_key' => (string) $template['key'],
            'template_name' => (string) $template['name'],
            'items' => $items,
        ], 'Landing template block keys');
    }

    public function applyTemplate(Request $request, int $id): JsonResponse
    {
        $page = $this->findPageForCurrentOrg($request, $id);
        if (!$page) {
            return $this->fail('Landing page not found', ['code' => 'LANDING_PAGE_NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'template_key' => ['required', 'string', 'max:120'],
            'replace_blocks' => ['nullable', 'boolean'],
            'replace_content' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
            'include_block_keys' => ['nullable', 'array', 'min:1'],
            'include_block_keys.*' => ['required', 'string', 'max:120'],
        ]);

        $template = collect($this->landingTemplates())
            ->first(fn(array $row): bool => (string) $row['key'] === (string) $validated['template_key']);

        if (!$template) {
            return $this->fail('Landing template not found', ['code' => 'LANDING_TEMPLATE_NOT_FOUND'], 404);
        }

        $replaceBlocks = (bool) ($validated['replace_blocks'] ?? true);
        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $requestedBlockKeysRaw = collect((array) ($validated['include_block_keys'] ?? []))
            ->map(fn($value): string => trim((string) $value))
            ->filter(fn(string $value): bool => $value !== '')
            ->values();
        $requestedBlockKeysNormalizedAll = collect((array) ($validated['include_block_keys'] ?? []))
            ->map(fn($value): string => strtolower(trim((string) $value)))
            ->filter(fn(string $value): bool => $value !== '')
            ->values();
        $duplicateRequestedBlockKeys = $requestedBlockKeysNormalizedAll
            ->countBy()
            ->filter(fn(int $count): bool => $count > 1)
            ->keys()
            ->values();
        $includeBlockKeys = $requestedBlockKeysNormalizedAll
            ->unique()
            ->values();
        $dedupedRequestedCount = max(0, $requestedBlockKeysNormalizedAll->count() - $includeBlockKeys->count());
        $normalizationChangedCount = $requestedBlockKeysRaw
            ->filter(fn(string $value): bool => strtolower($value) !== $value)
            ->count();
        $normalizationChangedKeys = $requestedBlockKeysRaw
            ->map(fn(string $value): array => [
                'raw' => $value,
                'normalized' => strtolower($value),
            ])
            ->filter(fn(array $row): bool => $row['raw'] !== $row['normalized'])
            ->pluck('normalized')
            ->unique()
            ->values();
        $normalizationChangedKeysRaw = $requestedBlockKeysRaw
            ->filter(fn(string $value): bool => strtolower($value) !== $value)
            ->unique()
            ->values();
        $selectionNormalizationEffectsBreakdown = [
            'dedupe_count' => $dedupedRequestedCount,
            'case_normalization_count' => $normalizationChangedCount,
        ];
        $selectionNormalizationEffectsBreakdownTotal = (int) array_sum($selectionNormalizationEffectsBreakdown);
        $selectionNormalizationEffectsBreakdownMatchesTotal = $selectionNormalizationEffectsBreakdownTotal === ($dedupedRequestedCount + $normalizationChangedCount);
        $selectionNormalizationEffectsDetails = [
            'dedupe_applied' => $dedupedRequestedCount > 0,
            'case_normalization_applied' => $normalizationChangedCount > 0,
        ];
        $selectionNormalizationEffectsDetailsActive = collect($selectionNormalizationEffectsDetails)
            ->filter(fn(bool $value): bool => $value)
            ->keys()
            ->values();
        $replaceContentProvided = array_key_exists('replace_content', $validated);
        $replaceContent = $replaceContentProvided
            ? (bool) $validated['replace_content']
            : $includeBlockKeys->isEmpty();

        $templateBlocks = (array) ($template['blocks'] ?? []);
        $availableTemplateBlockKeys = collect($templateBlocks)
            ->map(fn(array $row): string => (string) ($row['block_key'] ?? ''))
            ->filter(fn(string $value): bool => $value !== '')
            ->values();
        $availableTemplateBlockKeysNormalized = $availableTemplateBlockKeys
            ->map(fn(string $value): string => strtolower($value))
            ->values();

        if ($includeBlockKeys->isNotEmpty()) {
            $invalidBlockKeys = $includeBlockKeys
                ->reject(fn(string $value): bool => $availableTemplateBlockKeysNormalized->contains($value))
                ->values();

            if ($invalidBlockKeys->isNotEmpty()) {
                return $this->fail('Some include_block_keys are invalid', [
                    'code' => 'INVALID_INCLUDE_BLOCK_KEYS',
                    'invalid_block_keys' => $invalidBlockKeys,
                    'available_block_keys' => $availableTemplateBlockKeys,
                ], 422);
            }

            $allowed = $includeBlockKeys->all();
            $templateBlocks = array_values(array_filter(
                $templateBlocks,
                fn(array $row): bool => in_array(strtolower((string) ($row['block_key'] ?? '')), $allowed, true)
            ));

            $requestedOrder = array_values($allowed);
            usort($templateBlocks, function (array $a, array $b) use ($requestedOrder): int {
                $keyA = strtolower((string) ($a['block_key'] ?? ''));
                $keyB = strtolower((string) ($b['block_key'] ?? ''));
                $posA = array_search($keyA, $requestedOrder, true);
                $posB = array_search($keyB, $requestedOrder, true);
                $idxA = $posA === false ? PHP_INT_MAX : $posA;
                $idxB = $posB === false ? PHP_INT_MAX : $posB;

                return $idxA <=> $idxB;
            });
        }

        if (empty($templateBlocks)) {
            return $this->fail('No template blocks available for this template', [
                'code' => 'NO_MATCHED_TEMPLATE_BLOCKS',
                'available_block_keys' => $availableTemplateBlockKeys,
            ], 422);
        }

        $appliedTemplateBlockKeys = collect($templateBlocks)
            ->map(fn(array $row): string => (string) ($row['block_key'] ?? ''))
            ->filter(fn(string $value): bool => $value !== '')
            ->values();
        $requestedBlockCount = $includeBlockKeys->isNotEmpty() ? $includeBlockKeys->count() : null;
        $appliedBlockCount = $appliedTemplateBlockKeys->count();
        $existingBlocksCountBefore = (int) LandingBlock::query()
            ->where('landing_page_id', (int) $page->id)
            ->count();

        if ($dryRun) {
            $renamedBlockKeys = [];
            $takenKeys = $replaceBlocks
                ? []
                : LandingBlock::query()
                    ->where('landing_page_id', (int) $page->id)
                    ->pluck('block_key')
                    ->map(fn($value): string => (string) $value)
                    ->values()
                    ->all();

            $existingMaxSort = $replaceBlocks
                ? 0
                : (int) LandingBlock::query()
                    ->where('landing_page_id', (int) $page->id)
                    ->max('sort_order');

            $projectedBlocks = collect($templateBlocks)
                ->values()
                ->map(function (array $block, int $idx) use ($replaceBlocks, $existingMaxSort, &$takenKeys, &$renamedBlockKeys): array {
                    $incomingKey = (string) ($block['block_key'] ?? ('block_' . ($idx + 1)));
                    $finalKey = $incomingKey;

                    if (!$replaceBlocks) {
                        $suffix = 1;
                        while (in_array($finalKey, $takenKeys, true)) {
                            $suffix++;
                            $finalKey = $incomingKey . '_' . $suffix;
                        }
                    }

                    if ($finalKey !== $incomingKey) {
                        $renamedBlockKeys[] = [
                            'from' => $incomingKey,
                            'to' => $finalKey,
                        ];
                    }

                    $takenKeys[] = $finalKey;

                    return [
                        'block_key' => $finalKey,
                        'block_type' => (string) ($block['block_type'] ?? 'section'),
                        'sort_order' => $replaceBlocks ? ($idx + 1) : ($existingMaxSort + $idx + 1),
                        'is_visible' => (bool) ($block['is_visible'] ?? true),
                        'content' => $block['content'] ?? null,
                    ];
                })
                ->values();

            return $this->ok([
                'page' => $this->pagePayload($page),
                'template' => [
                    'key' => (string) $template['key'],
                    'name' => (string) $template['name'],
                ],
                'operation' => [
                    'replace_blocks' => $replaceBlocks,
                    'replace_content' => $replaceContent,
                    'dry_run' => true,
                ],
                'dry_run' => true,
                'preview' => [
                    'replace_blocks' => $replaceBlocks,
                    'replace_content' => $replaceContent,
                    'block_count' => $projectedBlocks->count(),
                ],
                'summary' => [
                    'requested_block_count' => $requestedBlockCount,
                    'applied_block_count' => $appliedBlockCount,
                    'existing_block_count_before' => $existingBlocksCountBefore,
                    'existing_block_count_after' => $replaceBlocks
                        ? $projectedBlocks->count()
                        : $existingBlocksCountBefore + $projectedBlocks->count(),
                    'resulting_block_count' => $replaceBlocks
                        ? $projectedBlocks->count()
                        : $existingBlocksCountBefore + $projectedBlocks->count(),
                ],
                'selection' => [
                    'requested_block_keys_raw' => $requestedBlockKeysRaw,
                    'requested_block_keys_raw_count' => $requestedBlockKeysRaw->count(),
                    'requested_block_keys' => $includeBlockKeys,
                    'requested_block_keys_unique_count' => $includeBlockKeys->count(),
                    'duplicate_requested_block_keys' => $duplicateRequestedBlockKeys,
                    'has_duplicate_requested_keys' => $duplicateRequestedBlockKeys->isNotEmpty(),
                    'duplicate_requested_count' => $duplicateRequestedBlockKeys->count(),
                    'deduped_requested_count' => $dedupedRequestedCount,
                    'has_normalization_changes' => $normalizationChangedCount > 0,
                    'normalization_changed_count' => $normalizationChangedCount,
                    'normalization_changed_keys' => $normalizationChangedKeys,
                    'normalization_changed_keys_count' => $normalizationChangedKeys->count(),
                    'has_normalization_changed_keys' => $normalizationChangedKeys->isNotEmpty(),
                    'normalization_changed_keys_raw' => $normalizationChangedKeysRaw,
                    'normalization_changed_keys_raw_count' => $normalizationChangedKeysRaw->count(),
                    'has_normalization_changed_keys_raw' => $normalizationChangedKeysRaw->isNotEmpty(),
                    'has_selection_normalization_effects' => $dedupedRequestedCount > 0 || $normalizationChangedCount > 0,
                    'selection_normalization_effects_count' => $dedupedRequestedCount + $normalizationChangedCount,
                    'selection_normalization_effects_breakdown' => $selectionNormalizationEffectsBreakdown,
                    'selection_normalization_effects_breakdown_total' => $selectionNormalizationEffectsBreakdownTotal,
                    'selection_normalization_effects_breakdown_matches_total' => $selectionNormalizationEffectsBreakdownMatchesTotal,
                    'selection_normalization_effects_details' => $selectionNormalizationEffectsDetails,
                    'selection_normalization_effects_details_count' => $selectionNormalizationEffectsDetailsActive->count(),
                    'selection_normalization_effects_details_active' => $selectionNormalizationEffectsDetailsActive,
                    'applied_block_keys' => $appliedTemplateBlockKeys,
                    'renamed_block_keys' => $renamedBlockKeys,
                    'has_renamed_keys' => count($renamedBlockKeys) > 0,
                    'renamed_count' => count($renamedBlockKeys),
                ],
                'blocks' => $projectedBlocks,
            ], 'Landing template dry-run');
        }

        $snapshotVersion = null;
        $renamedBlockKeys = [];

        try {
            DB::transaction(function () use ($page, $template, $replaceBlocks, $replaceContent, $templateBlocks, &$snapshotVersion, &$renamedBlockKeys): void {
                if ($replaceContent) {
                    $page->content = $template['content'] ?? null;
                }

                $page->status = 'draft';
                $page->published_at = null;
                $page->save();

                if ($replaceBlocks) {
                    LandingBlock::query()->where('landing_page_id', (int) $page->id)->delete();
                }

                $existingMaxSort = (int) LandingBlock::query()
                    ->where('landing_page_id', (int) $page->id)
                    ->max('sort_order');

                foreach ($templateBlocks as $idx => $block) {
                    $incomingKey = (string) ($block['block_key'] ?? ('block_' . ($idx + 1)));
                    $finalKey = $incomingKey;

                    if (!$replaceBlocks) {
                        $suffix = 1;
                        while (LandingBlock::query()
                            ->where('landing_page_id', (int) $page->id)
                            ->where('block_key', $finalKey)
                            ->exists()) {
                            $suffix++;
                            $finalKey = $incomingKey . '_' . $suffix;
                        }
                    }

                    if ($finalKey !== $incomingKey) {
                        $renamedBlockKeys[] = [
                            'from' => $incomingKey,
                            'to' => $finalKey,
                        ];
                    }

                    $sortOrder = $replaceBlocks
                        ? ($idx + 1)
                        : $existingMaxSort + $idx + 1;

                    LandingBlock::query()->create([
                        'organization_id' => (int) $page->organization_id,
                        'landing_page_id' => (int) $page->id,
                        'block_key' => $finalKey,
                        'block_type' => (string) ($block['block_type'] ?? 'section'),
                        'sort_order' => $sortOrder,
                        'is_visible' => (bool) ($block['is_visible'] ?? true),
                        'content' => $block['content'] ?? null,
                    ]);
                }

                $snapshotVersion = $this->snapshotVersion($page, 'draft');
            });
        } catch (\InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), ['code' => 'NO_MATCHED_TEMPLATE_BLOCKS'], 422);
        }

        $appliedBlocks = LandingBlock::query()
            ->where('landing_page_id', (int) $page->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn(LandingBlock $block) => $this->blockPayload($block))
            ->values();

        return $this->ok([
            'page' => $this->pagePayload($page->fresh()),
            'template' => [
                'key' => (string) $template['key'],
                'name' => (string) $template['name'],
            ],
            'operation' => [
                'replace_blocks' => $replaceBlocks,
                'replace_content' => $replaceContent,
                'dry_run' => false,
            ],
            'summary' => [
                'requested_block_count' => $requestedBlockCount,
                'applied_block_count' => $appliedBlockCount,
                'existing_block_count_before' => $existingBlocksCountBefore,
                'existing_block_count_after' => $appliedBlocks->count(),
                'resulting_block_count' => $appliedBlocks->count(),
            ],
            'selection' => [
                'requested_block_keys_raw' => $requestedBlockKeysRaw,
                'requested_block_keys_raw_count' => $requestedBlockKeysRaw->count(),
                'requested_block_keys' => $includeBlockKeys,
                'requested_block_keys_unique_count' => $includeBlockKeys->count(),
                'duplicate_requested_block_keys' => $duplicateRequestedBlockKeys,
                'has_duplicate_requested_keys' => $duplicateRequestedBlockKeys->isNotEmpty(),
                'duplicate_requested_count' => $duplicateRequestedBlockKeys->count(),
                'deduped_requested_count' => $dedupedRequestedCount,
                'has_normalization_changes' => $normalizationChangedCount > 0,
                'normalization_changed_count' => $normalizationChangedCount,
                'normalization_changed_keys' => $normalizationChangedKeys,
                'normalization_changed_keys_count' => $normalizationChangedKeys->count(),
                'has_normalization_changed_keys' => $normalizationChangedKeys->isNotEmpty(),
                'normalization_changed_keys_raw' => $normalizationChangedKeysRaw,
                'normalization_changed_keys_raw_count' => $normalizationChangedKeysRaw->count(),
                'has_normalization_changed_keys_raw' => $normalizationChangedKeysRaw->isNotEmpty(),
                'has_selection_normalization_effects' => $dedupedRequestedCount > 0 || $normalizationChangedCount > 0,
                'selection_normalization_effects_count' => $dedupedRequestedCount + $normalizationChangedCount,
                'selection_normalization_effects_breakdown' => $selectionNormalizationEffectsBreakdown,
                'selection_normalization_effects_breakdown_total' => $selectionNormalizationEffectsBreakdownTotal,
                'selection_normalization_effects_breakdown_matches_total' => $selectionNormalizationEffectsBreakdownMatchesTotal,
                'selection_normalization_effects_details' => $selectionNormalizationEffectsDetails,
                'selection_normalization_effects_details_count' => $selectionNormalizationEffectsDetailsActive->count(),
                'selection_normalization_effects_details_active' => $selectionNormalizationEffectsDetailsActive,
                'applied_block_keys' => $appliedTemplateBlockKeys,
                'renamed_block_keys' => $renamedBlockKeys,
                'has_renamed_keys' => count($renamedBlockKeys) > 0,
                'renamed_count' => count($renamedBlockKeys),
            ],
            'version' => $snapshotVersion ? [
                'id' => (int) $snapshotVersion->id,
                'version_no' => (int) $snapshotVersion->version_no,
                'source_status' => (string) $snapshotVersion->source_status,
                'created_at' => $snapshotVersion->created_at,
            ] : null,
            'blocks' => $appliedBlocks,
        ], 'Landing template applied');
    }

    public function publicShow(string $organizationSlug, string $pageSlug): JsonResponse
    {
        $page = OrganizationLandingPage::query()
            ->whereHas('organization', fn($query) => $query->where('slug', $organizationSlug))
            ->where('slug', $pageSlug)
            ->where('status', 'published')
            ->first();

        if (!$page) {
            return $this->fail('Published landing page not found', ['code' => 'PUBLISHED_LANDING_PAGE_NOT_FOUND'], 404);
        }

        $this->trackPublicView($page);

        $payload = $this->publicPayload($page, '/landing/' . $organizationSlug . '/' . $pageSlug, sprintf('landing:%s:%s', $organizationSlug, $pageSlug));

        return $this->ok($payload, 'Public landing page')
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }

    public function publicShowByOrganization(string $organizationSlug): JsonResponse
    {
        $page = OrganizationLandingPage::query()
            ->whereHas('organization', fn($query) => $query->where('slug', $organizationSlug))
            ->where('status', 'published')
            ->orderByRaw("CASE WHEN slug = 'landing-page' THEN 0 ELSE 1 END")
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        if (!$page) {
            return $this->fail('Published landing page not found', ['code' => 'PUBLISHED_LANDING_PAGE_NOT_FOUND'], 404);
        }

        $this->trackPublicView($page);

        $payload = $this->publicPayload($page, '/p/landingpage/' . $organizationSlug, sprintf('landingpage:%s', $organizationSlug));

        return $this->ok($payload, 'Public landing page by organization')
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }

    public function publicShowByDomain(string $domain): JsonResponse
    {
        $normalized = $this->normalizeDomain($domain);
        if ($normalized === '') {
            return $this->fail('Invalid domain format', ['code' => 'INVALID_DOMAIN'], 422);
        }

        $landingDomain = LandingDomain::query()
            ->with('landingPage')
            ->where('domain', $normalized)
            ->where('status', 'verified')
            ->first();

        if (!$landingDomain || !$landingDomain->landingPage) {
            return $this->fail('Published landing page not found for domain', ['code' => 'DOMAIN_LANDING_NOT_FOUND'], 404);
        }

        $page = $landingDomain->landingPage;
        if ((string) $page->status !== 'published') {
            return $this->fail('Published landing page not found for domain', ['code' => 'DOMAIN_LANDING_NOT_FOUND'], 404);
        }

        $this->trackPublicView($page);

        $payload = $this->publicPayload($page, '/landing/domain/' . $normalized, sprintf('landing-domain:%s', $normalized));

        return $this->ok($payload, 'Public landing page (domain)')
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }

    public function publicStoreCustomer(Request $request, int $landingPageId): JsonResponse
    {
        $page = OrganizationLandingPage::query()
            ->where('id', $landingPageId)
            ->where('status', 'published')
            ->first();

        if (!$page) {
            return $this->fail('Published landing page not found', ['code' => 'PUBLISHED_LANDING_PAGE_NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'block_id' => ['nullable', 'string', 'max:80'],
            'form_title' => ['nullable', 'string', 'max:160'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['nullable', 'string', 'max:2000'],
        ]);

        $fields = collect($validated['fields'])
            ->mapWithKeys(fn($value, $key) => [(string) $key => is_scalar($value) ? trim((string) $value) : ''])
            ->filter(fn($value) => $value !== '')
            ->all();

        $customer = CustomerLandingpage::query()->create([
            'organization_id' => (int) $page->organization_id,
            'landing_page_id' => (int) $page->id,
            'block_id' => $validated['block_id'] ?? null,
            'form_title' => $validated['form_title'] ?? null,
            'name' => $fields['name'] ?? $fields['full_name'] ?? $fields['nama'] ?? null,
            'phone' => $fields['phone'] ?? $fields['whatsapp'] ?? $fields['nomor_hp'] ?? null,
            'email' => $fields['email'] ?? null,
            'fields' => $fields,
            'source_url' => $request->headers->get('referer'),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 2000),
        ]);

        return $this->ok($this->customerPayload($customer), 'Customer landing page submitted', 201);
    }

    private function publicPayload(OrganizationLandingPage $page, string $canonicalPath, string $cacheKey): array
    {
        $hero = is_array($page->content) && isset($page->content['hero']) && is_array($page->content['hero'])
            ? $page->content['hero']
            : [];

        $title = (string) ($hero['title'] ?? $page->title);
        $description = (string) ($hero['subtitle'] ?? 'Landing page published via Hellom Landing Builder');

        $blocks = LandingBlock::query()
            ->where('landing_page_id', (int) $page->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn(LandingBlock $block) => [
                'id' => (int) $block->id,
                'block_key' => (string) $block->block_key,
                'block_type' => (string) $block->block_type,
                'sort_order' => (int) $block->sort_order,
                'content' => $block->content,
            ])
            ->values();

        return [
            'page' => $this->pagePayload($page),
            'blocks' => $blocks,
            'seo' => [
                'title' => $title,
                'description' => $description,
                'canonical_path' => $canonicalPath,
            ],
            'render' => [
                'strategy' => 'isr',
                'revalidate_seconds' => 60,
                'cache_key' => $cacheKey,
                'generated_at' => now()->toISOString(),
            ],
        ];
    }

    private function findPageForCurrentOrg(Request $request, int $id): ?OrganizationLandingPage
    {
        $organizationId = $this->resolveOrganizationId($request);
        if ($organizationId <= 0) {
            return null;
        }

        return OrganizationLandingPage::query()
            ->where('organization_id', $organizationId)
            ->where('id', $id)
            ->first();
    }

    private function resolveOrganizationId(Request $request): int
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return 0;
        }

        return (int) ($user->current_organization_id ?? 0);
    }

    private function uniqueSlug(int $organizationId, string $seed, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($seed);
        if ($baseSlug === '') {
            $baseSlug = 'landing-page';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (OrganizationLandingPage::query()
            ->where('organization_id', $organizationId)
            ->where('slug', $slug)
            ->when($ignoreId, fn($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $counter++;
            $slug = $baseSlug . '-' . $counter;
        }

        return $slug;
    }

    private function pagePayload(OrganizationLandingPage $page): array
    {
        $organizationSlug = (string) ($page->relationLoaded('organization')
            ? ($page->organization?->slug ?? '')
            : (Organization::query()->whereKey((int) $page->organization_id)->value('slug') ?? ''));

        return [
            'id' => $page->id,
            'organization_id' => (int) $page->organization_id,
            'title' => (string) $page->title,
            'slug' => (string) $page->slug,
            'status' => (string) $page->status,
            'public_url' => $organizationSlug !== '' ? '/p/landingpage/' . $organizationSlug : null,
            'legacy_public_url' => $organizationSlug !== '' ? '/p/' . $organizationSlug . '/' . (string) $page->slug : null,
            'content' => $page->content,
            'published_at' => $page->published_at,
            'created_at' => $page->created_at,
            'updated_at' => $page->updated_at,
        ];
    }

    private function versionPayload(LandingPageVersion $version): array
    {
        return [
            'id' => (int) $version->id,
            'landing_page_id' => (int) $version->landing_page_id,
            'version_no' => (int) $version->version_no,
            'source_status' => (string) $version->source_status,
            'title' => (string) $version->title,
            'slug' => (string) $version->slug,
            'content' => $version->content,
            'published_at' => $version->published_at,
            'created_at' => $version->created_at,
        ];
    }

    private function domainPayload(LandingDomain $domain): array
    {
        return [
            'id' => (int) $domain->id,
            'landing_page_id' => (int) $domain->landing_page_id,
            'domain' => (string) $domain->domain,
            'is_primary' => (bool) $domain->is_primary,
            'status' => (string) $domain->status,
            'verified_at' => $domain->verified_at,
            'created_at' => $domain->created_at,
        ];
    }

    private function blockPayload(LandingBlock $block): array
    {
        return [
            'id' => (int) $block->id,
            'landing_page_id' => (int) $block->landing_page_id,
            'block_key' => (string) $block->block_key,
            'block_type' => (string) $block->block_type,
            'sort_order' => (int) $block->sort_order,
            'is_visible' => (bool) $block->is_visible,
            'content' => $block->content,
            'created_at' => $block->created_at,
            'updated_at' => $block->updated_at,
        ];
    }

    private function customerPayload(CustomerLandingpage $customer): array
    {
        return [
            'id' => (int) $customer->id,
            'organization_id' => (int) $customer->organization_id,
            'landing_page_id' => (int) $customer->landing_page_id,
            'landing_page' => $customer->relationLoaded('landingPage') && $customer->landingPage ? [
                'id' => (int) $customer->landingPage->id,
                'title' => (string) $customer->landingPage->title,
                'slug' => (string) $customer->landingPage->slug,
            ] : null,
            'block_id' => $customer->block_id,
            'form_title' => $customer->form_title,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'fields' => $customer->fields,
            'source_url' => $customer->source_url,
            'created_at' => $customer->created_at,
        ];
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');
        if (str_contains($domain, '/')) {
            return '';
        }

        return $domain;
    }

    private function landingTemplates(): array
    {
        return [
            [
                'key' => 'restaurant-basic',
                'name' => 'Restaurant Basic',
                'description' => 'Template landing sederhana untuk promo restoran harian.',
                'content' => [
                    'hero' => [
                        'title' => 'Makan Enak Tanpa Ribet',
                        'subtitle' => 'Order online cepat, promo harian, dan pengantaran tepat waktu.',
                    ],
                ],
                'blocks' => [
                    [
                        'block_key' => 'hero_main',
                        'block_type' => 'hero',
                        'sort_order' => 1,
                        'is_visible' => true,
                        'content' => [
                            'title' => 'Makan Enak Tanpa Ribet',
                            'subtitle' => 'Order online cepat, promo harian, dan pengantaran tepat waktu.',
                            'cta_label' => 'Pesan Sekarang',
                        ],
                    ],
                    [
                        'block_key' => 'features_main',
                        'block_type' => 'features',
                        'sort_order' => 2,
                        'is_visible' => true,
                        'content' => [
                            'items' => [
                                ['title' => 'Cepat', 'text' => 'Checkout kilat kurang dari 1 menit.'],
                                ['title' => 'Praktis', 'text' => 'Menu lengkap dengan opsi custom.'],
                                ['title' => 'Aman', 'text' => 'Status pesanan bisa dipantau real-time.'],
                            ],
                        ],
                    ],
                    [
                        'block_key' => 'cta_main',
                        'block_type' => 'cta',
                        'sort_order' => 3,
                        'is_visible' => true,
                        'content' => [
                            'title' => 'Siap Coba Hari Ini?',
                            'button_label' => 'Mulai Order',
                        ],
                    ],
                ],
            ],
            [
                'key' => 'reservation-focus',
                'name' => 'Reservation Focus',
                'description' => 'Template landing fokus reservasi meja dan event kecil.',
                'content' => [
                    'hero' => [
                        'title' => 'Reservasi Meja Lebih Mudah',
                        'subtitle' => 'Pilih slot, konfirmasi cepat, dan nikmati pengalaman dine-in terbaik.',
                    ],
                ],
                'blocks' => [
                    [
                        'block_key' => 'hero_reservation',
                        'block_type' => 'hero',
                        'sort_order' => 1,
                        'is_visible' => true,
                        'content' => [
                            'title' => 'Reservasi Meja Lebih Mudah',
                            'subtitle' => 'Pilih slot, konfirmasi cepat, dan nikmati pengalaman dine-in terbaik.',
                            'cta_label' => 'Reservasi Sekarang',
                        ],
                    ],
                    [
                        'block_key' => 'steps_reservation',
                        'block_type' => 'steps',
                        'sort_order' => 2,
                        'is_visible' => true,
                        'content' => [
                            'items' => [
                                ['title' => 'Pilih Tanggal', 'text' => 'Tentukan waktu kunjungan sesuai kebutuhan.'],
                                ['title' => 'Konfirmasi', 'text' => 'Dapatkan konfirmasi instan via sistem.'],
                                ['title' => 'Datang & Nikmati', 'text' => 'Meja siap saat kamu tiba.'],
                            ],
                        ],
                    ],
                    [
                        'block_key' => 'cta_reservation',
                        'block_type' => 'cta',
                        'sort_order' => 3,
                        'is_visible' => true,
                        'content' => [
                            'title' => 'Jadwalkan Kunjunganmu',
                            'button_label' => 'Lihat Slot Tersedia',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function snapshotVersion(OrganizationLandingPage $page, string $sourceStatus): LandingPageVersion
    {
        $nextVersion = (int) LandingPageVersion::query()
            ->where('landing_page_id', (int) $page->id)
            ->max('version_no') + 1;

        return LandingPageVersion::query()->create([
            'organization_id' => (int) $page->organization_id,
            'landing_page_id' => (int) $page->id,
            'version_no' => $nextVersion,
            'source_status' => $sourceStatus,
            'title' => (string) $page->title,
            'slug' => (string) $page->slug,
            'content' => $page->content,
            'published_at' => $page->published_at,
        ]);
    }

    private function trackPublishActivation(OrganizationLandingPage $page): void
    {
        $stat = LandingStat::query()->firstOrCreate(
            ['organization_id' => (int) $page->organization_id],
            [
                'first_published_page_id' => null,
                'first_published_at' => null,
                'published_count' => 0,
                'views_count' => 0,
                'last_viewed_at' => null,
            ]
        );

        if ($stat->first_published_at === null) {
            $stat->first_published_at = now();
            $stat->first_published_page_id = (int) $page->id;
        }

        $stat->published_count = (int) $stat->published_count + 1;
        $stat->save();
    }

    private function trackPublicView(OrganizationLandingPage $page): void
    {
        $stat = LandingStat::query()->firstOrCreate(
            ['organization_id' => (int) $page->organization_id],
            [
                'first_published_page_id' => null,
                'first_published_at' => null,
                'published_count' => 0,
                'views_count' => 0,
                'last_viewed_at' => null,
            ]
        );

        $stat->views_count = (int) ($stat->views_count ?? 0) + 1;
        $stat->last_viewed_at = now();
        $stat->save();

        $pageStat = LandingPageStat::query()->firstOrCreate(
            ['landing_page_id' => (int) $page->id],
            [
                'organization_id' => (int) $page->organization_id,
                'views_count' => 0,
                'last_viewed_at' => null,
            ]
        );

        $pageStat->views_count = (int) ($pageStat->views_count ?? 0) + 1;
        $pageStat->last_viewed_at = now();
        $pageStat->save();
    }
}
