<?php

namespace App\Services\Landing;

use App\Models\LandingBlock;
use App\Models\LandingPageVersion;
use App\Models\OrganizationLandingPage;
use App\Models\User;
use App\Support\ImageOptimizer;
use App\Support\Landing\BlockSchema;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Draft / publish of a Hellom Page (audit LB-06, LB-29):
 *   - the editor autosaves the whole document into organization_landing_pages.draft_document
 *     (one atomic write, stable block ids, revision check against a stale tab);
 *   - Publish copies the draft into an immutable landing_page_versions row; the public page
 *     renders only that snapshot, so editing never breaks the live page;
 *   - restore copies an old version back into the draft.
 * Pages from before Fase 4 (landing_blocks rows) are converted on first read.
 */
final class LandingDocumentService
{
    public function __construct(private readonly LandingShop $shop)
    {
    }

    /** @return array{document: array, revision: int, saved_at: ?string} */
    public function draft(OrganizationLandingPage $page): array
    {
        if (!is_array($page->draft_document)) {
            $document = $page->published_version_id
                ? (LandingPageVersion::query()->find($page->published_version_id)?->document ?? $this->fromLegacyBlocks($page))
                : $this->fromLegacyBlocks($page);
            $page->forceFill(['draft_document' => BlockSchema::normalize($document)])->save();
        }

        return [
            'document' => $page->draft_document,
            'revision' => (int) $page->draft_revision,
            'saved_at' => optional($page->draft_saved_at)->toIso8601String(),
        ];
    }

    /**
     * Save the draft. $expectedRevision (from the editor) must match the stored one, so an
     * older tab cannot silently overwrite newer work (409 CONFLICT in the controller).
     */
    public function saveDraft(OrganizationLandingPage $page, mixed $document, ?int $expectedRevision): array
    {
        $document = $this->storeInlineImages(is_array($document) ? $document : [], (int) $page->organization_id);
        $clean = BlockSchema::normalize($document);

        return DB::transaction(function () use ($page, $clean, $expectedRevision): array {
            $locked = OrganizationLandingPage::query()->lockForUpdate()->findOrFail($page->id);
            if ($expectedRevision !== null && $expectedRevision !== (int) $locked->draft_revision) {
                throw new RuntimeException('DRAFT_CONFLICT');
            }
            $locked->forceFill([
                'draft_document' => $clean,
                'draft_revision' => (int) $locked->draft_revision + 1,
                'draft_saved_at' => now(),
            ])->save();

            return ['document' => $clean, 'revision' => (int) $locked->draft_revision, 'saved_at' => $locked->draft_saved_at->toIso8601String()];
        }, 3);
    }

    /** Publish the current draft as a new version. Throws RuntimeException('PAGE_QUOTA') when over quota. */
    public function publish(OrganizationLandingPage $page, ?User $user): LandingPageVersion
    {
        $document = $this->draft($page)['document'];

        $version = DB::transaction(function () use ($page, $document, $user): LandingPageVersion {
            $locked = OrganizationLandingPage::query()->lockForUpdate()->findOrFail($page->id);
            if ($locked->status !== 'published') {
                $published = OrganizationLandingPage::query()->where('organization_id', $locked->organization_id)
                    ->where('status', 'published')->whereKeyNot($locked->id)->count();
                if ($published >= $this->shop->pageQuota((int) $locked->organization_id)) {
                    throw new RuntimeException('PAGE_QUOTA');
                }
            }
            $next = (int) LandingPageVersion::query()->where('landing_page_id', $locked->id)->max('version_no') + 1;
            $version = LandingPageVersion::query()->create([
                'organization_id' => $locked->organization_id,
                'landing_page_id' => $locked->id,
                'version_no' => $next,
                'source_status' => 'published',
                'title' => $locked->title,
                'slug' => $locked->slug,
                'content' => $locked->content,
                'document' => $document,
                'created_by_user_id' => $user?->id,
                'published_at' => now(),
            ]);
            $hasHome = OrganizationLandingPage::query()->where('organization_id', $locked->organization_id)->where('is_home', true)->exists();
            $locked->forceFill([
                'status' => 'published',
                'published_at' => now(),
                'published_version_id' => $version->id,
                'is_home' => $locked->is_home || !$hasHome,
            ])->save();

            return $version;
        }, 3);
        $this->shop->bumpCache((int) $page->organization_id);

        return $version;
    }

    public function unpublish(OrganizationLandingPage $page): void
    {
        $page->forceFill(['status' => 'draft'])->save();
        $this->shop->bumpCache((int) $page->organization_id);
    }

    /** Copy an older published version back into the draft (publish again to make it live). */
    public function restore(OrganizationLandingPage $page, LandingPageVersion $version): array
    {
        $document = $version->document ?? $this->fromLegacyContent($version->content);

        return $this->saveDraft($page, $document, null);
    }

    /** Published document of a page (what the public sees), or null. */
    public function published(OrganizationLandingPage $page): ?array
    {
        if (!$page->published_version_id) {
            return null;
        }
        $version = LandingPageVersion::query()->find($page->published_version_id);

        return $version?->document ? BlockSchema::normalize($version->document) : null;
    }

    /** Build a document from the pre-Fase 4 storage (landing_blocks + page.content). */
    public function fromLegacyBlocks(OrganizationLandingPage $page): array
    {
        $blocks = LandingBlock::query()->where('landing_page_id', $page->id)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (LandingBlock $b) => [
                'id' => 'b' . $b->id,
                'type' => (string) $b->block_type,
                'hidden' => !$b->is_visible,
                'content' => is_array($b->content) ? $b->content : [],
            ])->all();
        $document = $this->fromLegacyContent($page->content);
        $document['blocks'] = $blocks;

        return $this->storeInlineImages($document, (int) $page->organization_id);
    }

    /** Theme + settings from the old page.content json. */
    private function fromLegacyContent(mixed $content): array
    {
        $content = is_array($content) ? $content : [];

        return [
            'theme' => ['preset' => (string) ($content['theme'] ?? 'industrial')],
            'settings' => is_array($content['settings'] ?? null) ? $content['settings'] : [],
            'blocks' => [],
        ];
    }

    /**
     * The old editor stored uploaded images as base64 data URLs inside blocks (heavy pages).
     * Such values are saved as WebP files and replaced by their /media URL.
     */
    private function storeInlineImages(array $document, int $organizationId): array
    {
        array_walk_recursive($document, function (&$value) use ($organizationId): void {
            if (is_string($value) && str_starts_with($value, 'data:image/') && str_contains(substr($value, 0, 40), ';base64,')) {
                $binary = base64_decode(substr($value, strpos($value, ',') + 1), true);
                if ($binary === false || strlen($binary) > 8 * 1024 * 1024) {
                    $value = '';

                    return;
                }
                try {
                    $path = ImageOptimizer::storeWebpFromBinary($binary, 'landing-builder/' . $organizationId);
                    $value = '/' . trim((string) config('filesystems.disks.public.url', '/media'), '/') . '/' . $path;
                } catch (\Throwable) {
                    $value = '';
                }
            }
        });

        return $document;
    }
}
