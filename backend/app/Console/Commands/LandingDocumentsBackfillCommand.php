<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Services\Landing\LandingDocumentService;
use App\Support\Landing\BlockSchema;
use Illuminate\Console\Command;

/**
 * Fase 4 migration of existing Hellom Pages to draft/published documents:
 *  - builds the draft document from the old landing_blocks rows (base64 images become WebP files);
 *  - pages that are live get their first published snapshot, so the server-rendered page shows
 *    exactly what visitors saw before;
 *  - lists shops whose slug is now a reserved word (their public URL needs a username).
 * Report only by default; --force writes. Safe to run again (skips pages already converted).
 */
class LandingDocumentsBackfillCommand extends Command
{
    protected $signature = 'landing:documents-backfill {--force : write drafts and published snapshots}';

    protected $description = 'Convert existing landing pages to draft/published documents (report by default)';

    public function handle(LandingDocumentService $documents): int
    {
        $rows = [];
        OrganizationLandingPage::query()->orderBy('id')->each(function (OrganizationLandingPage $page) use ($documents, &$rows): void {
            $needsDraft = !is_array($page->draft_document);
            $needsSnapshot = $page->status === 'published' && !$page->published_version_id;
            if (!$needsDraft && !$needsSnapshot) {
                return;
            }
            $action = [];
            if ($this->option('force')) {
                if ($needsDraft) {
                    $page->forceFill(['draft_document' => BlockSchema::normalize($documents->fromLegacyBlocks($page)), 'draft_saved_at' => $page->updated_at ?? now()])->save();
                    $action[] = 'draft';
                }
                if ($needsSnapshot) {
                    $publishedAt = $page->published_at;
                    $documents->publish($page->fresh(), null);
                    $page->refresh()->forceFill(['published_at' => $publishedAt ?? $page->published_at])->save();
                    $action[] = 'published snapshot';
                }
            } else {
                $action[] = ($needsDraft ? 'would build draft' : '') . ($needsSnapshot ? ' + snapshot' : '');
            }
            $blocks = is_array($page->fresh()->draft_document) ? count($page->fresh()->draft_document['blocks'] ?? []) : '-';
            $rows[] = [$page->organization_id, $page->id, $page->slug, $page->status, $blocks, trim(implode(', ', $action))];
        });

        $rows === [] ? $this->info('All pages are already converted.') : $this->table(['organization', 'page', 'slug', 'status', 'blocks', 'action'], $rows);

        $reserved = Organization::query()->whereNull('landing_username')->whereIn('slug', config('landing.reserved_usernames', []))->get(['id', 'name', 'slug']);
        if ($reserved->isNotEmpty()) {
            $this->warn('Shops whose slug is a reserved word (their page is not reachable until they pick a username in the editor):');
            $this->table(['organization', 'name', 'slug'], $reserved->map(fn ($o) => [$o->id, $o->name, $o->slug])->all());
        }
        if (!$this->option('force') && $rows !== []) {
            $this->comment('Report only. Run with --force to write.');
        }

        return self::SUCCESS;
    }
}
