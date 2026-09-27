<?php

// Web routes. The product UI is the React SPA (frontend/, built into
// public/hellom). In production Nginx serves the SPA and only forwards
// /api, /storage, /media and /socket.io; these routes make
// `php artisan serve` behave the same way locally.
// The former Blade UI lives in _archive/blade-ui/.

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

	return response()->file($disk->path($path), [
		'Cache-Control' => 'public, max-age=31536000',
	]);
})->where('path', '.*')->name('media.public');

// Every other GET that is not an API call gets the SPA shell; the React
// router resolves the path (/, /login, /dashboard/..., /pos/..., /<org-slug>).
Route::fallback(function () {
	if (request()->is('api/*')) {
		abort(404);
	}

	$spaPath = public_path('hellom/index.html');

	if (!file_exists($spaPath)) {
		abort(503, 'Hellom UI assets not found. Run: npm --prefix frontend run build');
	}

	return response()->file($spaPath);
})->name('spa');
