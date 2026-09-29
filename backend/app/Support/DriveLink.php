<?php

namespace App\Support;

/**
 * Recognises Google Drive / Docs share links a seller pastes for a digital product.
 *
 * Note (docs/AUDIT_LANDING_BUILDER.md): a link shared as "Anyone with the link" can be
 * passed on by a buyer. The access page hides it and limits opens, but cannot stop that.
 * A later mode (products.delivery_mode = google_grant) would connect the seller's Google
 * account and share the file with the buyer's email through the Drive API.
 */
final class DriveLink
{
    /**
     * @return array{kind: string, id: string, url: string}|null kind: file|folder|document|spreadsheets|presentation|forms
     */
    public static function parse(string $url): ?array
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        if ($host === 'drive.google.com') {
            if (preg_match('#^/file/d/([A-Za-z0-9_-]{10,})#', $path, $m)) {
                return ['kind' => 'file', 'id' => $m[1], 'url' => $url];
            }
            if (preg_match('#^/drive/(?:u/\d+/)?folders/([A-Za-z0-9_-]{10,})#', $path, $m)) {
                return ['kind' => 'folder', 'id' => $m[1], 'url' => $url];
            }
            if (in_array($path, ['/open', '/uc'], true) && is_string($query['id'] ?? null) && preg_match('#^[A-Za-z0-9_-]{10,}$#', $query['id'])) {
                return ['kind' => 'file', 'id' => $query['id'], 'url' => $url];
            }

            return null;
        }
        if ($host === 'docs.google.com' && preg_match('#^/(document|spreadsheets|presentation|forms)/d/(?:e/)?([A-Za-z0-9_-]{10,})#', $path, $m)) {
            return ['kind' => $m[1], 'id' => $m[2], 'url' => $url];
        }

        return null;
    }

    /** Any https URL (for link/access products). */
    public static function isSafeHttpsUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && filter_var(trim($url), FILTER_VALIDATE_URL) !== false;
    }
}
