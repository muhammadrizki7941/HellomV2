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
 *   v3  theme { …colors, headingFont, bodyFont, bg{type…}, button{shape, fill, borderWidth, shadow, hover} }
 *       (Fase 5): old theme.font / buttonShape / buttonStyle and button-block "style" move in.
 */
final class DocumentMigrator
{
    public const CURRENT = 3;

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
        if ($version < 3) {
            $document = self::v2ToV3($document);
        }
        $document['schema_version'] = self::CURRENT;

        return $document;
    }

    /**
     * v2 → v3: one font → heading + body font; button shape/style → theme.button; the page keeps its
     * solid background color. A button block's own "style" (solid/outline) becomes its "fill".
     */
    private static function v2ToV3(array $document): array
    {
        $theme = is_array($document['theme'] ?? null) ? $document['theme'] : [];
        if (isset($theme['font']) && is_string($theme['font'])) {
            $theme['headingFont'] ??= $theme['font'];
            $theme['bodyFont'] ??= $theme['font'];
        }
        $button = is_array($theme['button'] ?? null) ? $theme['button'] : [];
        if (isset($theme['buttonShape'])) {
            $button['shape'] ??= $theme['buttonShape'];
        }
        if (isset($theme['buttonStyle'])) {
            $button['fill'] ??= $theme['buttonStyle'] === 'outline' ? 'outline' : 'solid';
        }
        unset($theme['font'], $theme['buttonShape'], $theme['buttonStyle']);
        if ($button !== []) {
            $theme['button'] = $button;
        }
        $document['theme'] = $theme;

        $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
        foreach ($blocks as $i => $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'button' && is_array($block['content'] ?? null) && isset($block['content']['style'])) {
                // Its own choice stays its own (a "solid" button on an outline theme stays solid).
                $block['content']['fill'] ??= $block['content']['style'] === 'outline' ? 'outline' : 'solid';
                unset($block['content']['style']);
                $blocks[$i] = $block;
            }
        }
        $document['blocks'] = $blocks;
        $document['schema_version'] = 3;

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
