<?php

namespace App\Support\Landing;

use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for the server-rendered shop pages (/{username}/…, custom domains, preview).
 *
 * The HTML is cached, so the CSP cannot use a per-request nonce: the one inline script
 * (views/landing/script.js) is allowed by its SHA-256 hash, and 'strict-dynamic' lets it load
 * the seller's ad pixels (Meta/Google/TikTok) after consent. Seller content is escaped by
 * Blade and never rendered as raw HTML; the CSP is the second line of defence.
 */
final class PageSecurity
{
    public static function apply(Response $response): Response
    {
        $response->headers->set('Content-Security-Policy', self::policy());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        return $response;
    }

    public static function policy(): string
    {
        $frontend = self::origin((string) config('app.frontend_url'));
        $app = self::origin((string) config('app.url'));
        $origins = implode(' ', array_unique(array_filter([$frontend, $app])));

        return implode('; ', [
            "default-src 'self'",
            // Hash + strict-dynamic for modern browsers; the https: host list is only the
            // fallback for browsers without strict-dynamic.
            "script-src 'sha256-" . self::scriptHash() . "' 'strict-dynamic' https://connect.facebook.net https://www.googletagmanager.com https://analytics.tiktok.com",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "media-src 'self' https:",
            // Pixels send events to many hosts (facebook.com, google-analytics, doubleclick…).
            "connect-src 'self' {$origins} https:",
            'frame-src https://www.youtube-nocookie.com https://www.youtube.com',
            "frame-ancestors 'self' {$origins}",
            "form-action 'self' {$origins}",
            "base-uri 'none'",
            "object-src 'none'",
        ]);
    }

    /** Base64 SHA-256 of the inline page script, exactly as layout.blade.php prints it. */
    public static function scriptHash(): string
    {
        $path = resource_path('views/landing/script.js');
        static $memo = [];
        $key = $path . ':' . filemtime($path);

        return $memo[$key] ??= base64_encode(hash('sha256', (string) file_get_contents($path), true));
    }

    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
