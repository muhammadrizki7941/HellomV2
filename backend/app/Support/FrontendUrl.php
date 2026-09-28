<?php

namespace App\Support;

/**
 * Absolute URLs into the SPA (payment return pages, email links). Built from
 * FRONTEND_URL (falls back to APP_URL) so nothing depends on a hardcoded host
 * or an old path prefix.
 */
final class FrontendUrl
{
    public static function to(string $path = '/'): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return $base . '/' . ltrim($path, '/');
    }
}
