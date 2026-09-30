<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The React app shell (frontend build in public/hellom). Served for every non-API path
 * that is not a Hellom Page shop; the React router resolves it.
 */
class SpaController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if ($request->is('api/*')) {
            abort(404);
        }
        // A verified custom domain shows its Hellom Page at "/".
        if ($custom = app(LandingPublicController::class)->forCustomDomain($request)) {
            return $custom;
        }
        // Files of the frontend build (/assets/*.js, sw.js, manifest, fonts…). Nginx serves them
        // from public/hellom in production; `php artisan serve` does not, and answering them with
        // the HTML shell left a white screen (e.g. invitation links from email).
        if ($file = self::buildFile($request->path())) {
            return $file;
        }
        if ($request->is('assets/*')) {
            abort(404);
        }

        return self::shell();
    }

    private const MIME = [
        'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8',
        'json' => 'application/json', 'webmanifest' => 'application/manifest+json', 'map' => 'application/json',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
        'gif' => 'image/gif', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf',
        'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml',
    ];

    private static function buildFile(string $path): ?Response
    {
        $root = realpath(public_path('hellom'));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!$root || $path === '' || $path === '/' || !isset(self::MIME[$extension])) {
            return null;
        }
        $full = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/')));
        if (!$full || !is_file($full) || !str_starts_with($full, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $hashed = str_starts_with(ltrim($path, '/'), 'assets/');

        return response()->file($full, [
            'Content-Type' => self::MIME[$extension],
            'Cache-Control' => $hashed ? 'public, max-age=31536000, immutable' : 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function shell(): Response
    {
        $spaPath = public_path('hellom/index.html');
        if (!file_exists($spaPath)) {
            abort(503, 'Hellom UI assets not found. Run: npm --prefix frontend run build');
        }

        return response()->file($spaPath, ['Cache-Control' => 'no-cache']);
    }
}
