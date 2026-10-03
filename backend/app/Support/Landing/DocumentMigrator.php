<?php

namespace App\Support\Landing;

/**
 * Upgrades a Hellom Page document to the current schema_version, one step at a time.
 *
 * Runs on every read and write (BlockSchema::normalize), so drafts, published versions and
 * history are upgraded when they are used — stored rows are never rewritten in bulk and an
 * old version can always be restored. A step only reshapes data; it never drops content the
 * page shows. To change the schema: add a step N → N+1, raise CURRENT, add a test.
 *
 *   v1  { version: 1, theme, settings, blocks }        (Fase 4 editor)
 *   v2  { schema_version: 2, theme, settings, blocks } (link-in-bio editor): additive — new
 *       optional fields arrive per phase in BlockSchema, existing blocks keep their meaning.
 */
final class DocumentMigrator
{
    public const CURRENT = 2;

    /** Version of a stored document (documents before versioning count as 1). */
    public static function version(array $document): int
    {
        $version = $document['schema_version'] ?? $document['version'] ?? 1;

        return is_numeric($version) ? max(1, (int) $version) : 1;
    }

    /** @return array<string, mixed> the document at schema_version CURRENT (not yet sanitised) */
    public static function upgrade(array $document): array
    {
        $version = self::version($document);
        if ($version > self::CURRENT) {
            // Written by a newer app (e.g. during a rollback): keep what this version understands.
            $version = self::CURRENT;
        }
        if ($version < 2) {
            $document = self::v1ToV2($document);
        }
        $document['schema_version'] = self::CURRENT;

        return $document;
    }

    /** v1 → v2: version key renamed; block styles that older editors kept inside content move out. */
    private static function v1ToV2(array $document): array
    {
        unset($document['version']);
        $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                continue;
            }
            $content = is_array($block['content'] ?? null) ? $block['content'] : [];
            if (!isset($block['styles']) && is_array($content['styles'] ?? null)) {
                $block['styles'] = $content['styles'];
            }
            unset($content['styles']);
            $block['content'] = $content;
            $blocks[$i] = $block;
        }
        $document['blocks'] = $blocks;
        $document['schema_version'] = 2;

        return $document;
    }
}
