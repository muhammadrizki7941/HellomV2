<?php

// Web routes. The product UI is the React SPA (frontend/, built into
// public/hellom). Hellom Page shops are rendered here on the server, so Nginx
// sends every non-file path to Laravel (see deploy/nginx); this file then serves
// either a shop page or the SPA shell.
// The former Blade UI lives in _archive/blade-ui/.

use App\Http\Controllers\LandingPublicController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Public media fallback endpoint (no symlink dependency)
Route::get('/media/{path}', function (string $path) {
	if (Str::contains($path, ['../', '..\\'])) {
		abort(404);
	}

	$disk = Storage::disk('public');
	if (!$disk->exists($path)) {
		abort(404);
	}

	$headers = [
		'Cache-Control' => 'public, max-age=31536000',
		'X-Content-Type-Options' => 'nosniff',
	];
	// Old SVG uploads can contain scripts: never let them run on this origin.
	if (Str::endsWith(Str::lower($path), '.svg')) {
		$headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
	}

	return response()->file($disk->path($path), $headers);
})->where('path', '.*')->name('media.public');

// Hellom Page fonts (App\Support\Landing\Fonts). Production: Nginx serves them from the SPA build
// before Laravel (`location ^~ /fonts/landing/` with CORS, deploy/nginx); this route covers
// `php artisan serve` (dev, e2e; Vite proxies to it). Built copy first, then source.
Route::get('/fonts/landing/{file}', function (string $file) {
	foreach ([public_path('hellom/fonts/landing/' . $file), base_path('../frontend/public/fonts/landing/' . $file)] as $path) {
		if (is_file($path)) {
			return response()->file($path, ['Content-Type' => 'font/woff2', 'Cache-Control' => 'public, max-age=31536000, immutable', 'Access-Control-Allow-Origin' => '*']);
		}
	}
	abort(404);
})->where('file', '[a-z0-9-]+\.woff2')
	// Static files: no session/cookies, so browsers and CDNs can cache them.
	->withoutMiddleware([
		\Illuminate\Session\Middleware\StartSession::class,
		\Illuminate\Cookie\Middleware\EncryptCookies::class,
		\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
		\Illuminate\View\Middleware\ShareErrorsFromSession::class,
		\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
	])
	->name('landing.fonts');

// Share card image (og:image) of a published page, Fase 6. No extension: Nginx sends it to Laravel.
Route::get('/og/{username}/{key}', [LandingPublicController::class, 'ogImage'])
	->where(['username' => '[a-z0-9][a-z0-9-]{0,38}[a-z0-9]', 'key' => '([a-z0-9-]+|_)-[a-f0-9]{12}'])
	->withoutMiddleware([
		\Illuminate\Session\Middleware\StartSession::class,
		\Illuminate\Cookie\Middleware\EncryptCookies::class,
		\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
		\Illuminate\View\Middleware\ShareErrorsFromSession::class,
		\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
	])
	->middleware('throttle:60,1')
	->name('landing.og');

// Hellom Page (Fase 4): server-rendered shop pages. /{username} and /{username}/{slug};
// reserved words and unknown usernames fall through to the SPA shell. The editor
// preview of a draft uses a signed link.
Route::get('/_preview/landing/{page}', [LandingPublicController::class, 'preview'])
	->whereNumber('page')->middleware('signed')->name('landing.preview');
Route::get('/{username}/{slug?}', [LandingPublicController::class, 'show'])
	->where('username', '[a-z0-9][a-z0-9-]{0,38}[a-z0-9]') // 2+ chars: older shops have short slugs
	->where('slug', '[a-z0-9][a-z0-9-]{0,119}')
	->name('landing.public');

// Every other GET that is not an API call gets the SPA shell; the React
// router resolves the path (/, /login, /dashboard/..., /pos/...).
Route::fallback(SpaController::class)->name('spa');
