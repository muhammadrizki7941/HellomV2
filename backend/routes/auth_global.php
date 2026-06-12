<?php

use App\Http\Controllers\Auth\GlobalLoginController;
use Illuminate\Support\Facades\Route;

$domains = (array) config('tenancy.app_domains', [config('tenancy.app_domain')]);
foreach ($domains as $domain) {
	if (!is_string($domain) || trim($domain) === '') {
		continue;
	}

	Route::domain($domain)->group(function () {
		Route::get('/login', [GlobalLoginController::class, 'show'])->name('auth.login');
		Route::post('/login', [GlobalLoginController::class, 'login'])->name('auth.login.submit');
		Route::post('/logout', [GlobalLoginController::class, 'logout'])->name('auth.logout');
	});
}
