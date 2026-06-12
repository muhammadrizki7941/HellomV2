<?php

use App\Http\Controllers\Marketing\ContactController;
use App\Http\Controllers\Marketing\LandingController;
use App\Http\Controllers\Public\PublicController;
use Illuminate\Support\Facades\Route;

$domains = (array) config('tenancy.app_domains', [config('tenancy.app_domain')]);
foreach ($domains as $domain) {
	if (!is_string($domain) || trim($domain) === '') {
		continue;
	}

	Route::domain($domain)->group(function () {
		Route::get('/', [PublicController::class, 'landing'])->name('marketing.landing');
		Route::get('/features', [LandingController::class, 'features'])->name('marketing.features');
		Route::get('/pricing', [LandingController::class, 'pricing'])->name('marketing.pricing');

		Route::get('/contact', [ContactController::class, 'show'])->name('marketing.contact');
		Route::post('/contact', [ContactController::class, 'submit'])->name('marketing.contact.submit');
	});
}
