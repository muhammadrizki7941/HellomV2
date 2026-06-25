<?php

// Single Restaurant App Routes
// Simplified from multi-tenant architecture

use App\Http\Controllers\Public\PublicController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Route;
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

	return response()->file($disk->path($path), [
		'Cache-Control' => 'public, max-age=31536000',
	]);
})->where('path', '.*')->name('media.public');

// Hellom SPA (must be before legacy POS routes so /hellom/ is matched first)
Route::get('/hellom/{any?}', function () {
	$spaPath = public_path('hellom/index.html');

	if (!file_exists($spaPath)) {
		abort(503, 'Hellom UI assets not found. Run: npm --prefix plans/UI run build:laravel');
	}

	return response()->file($spaPath);
})->where('any', '.*')->name('hellom.spa');

// Root redirect — send visitors to Hellom platform (only for non-app domains)
$appDomains = (array) config('tenancy.app_domains', ['localhost', '127.0.0.1']);
$currentHost = request()->getHost();
$isAppDomain = in_array($currentHost, $appDomains, true);

Route::get('/', [PublicController::class, 'landing'])->name('landing');

// Legacy POS / Kasir routes (kept for backward compatibility)
require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/cashier.php';
require __DIR__.'/customer.php';

// Marketing landing pages (loaded after root redirect so it doesn't conflict)
require __DIR__.'/marketing.php';

// Short public landing page URL: domain.com/<org-slug> (e.g. /rudi-bengkel).
// Registered LAST so every real route above wins first; this only catches a
// leftover single-segment slug and serves the Hellom SPA, whose router then
// resolves the slug to the published landing page. The dot-less constraint
// keeps static files (favicon.ico, robots.txt, ...) from being swallowed.
Route::get('/{slug}', function () {
	$spaPath = public_path('hellom/index.html');

	if (!file_exists($spaPath)) {
		abort(503, 'Hellom UI assets not found. Run: npm --prefix plans/UI run build:laravel');
	}

	return response()->file($spaPath);
})->where('slug', '[A-Za-z0-9_-]+')->name('landingpage.short');
