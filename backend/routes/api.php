<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\Api\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

/*
| Hellom API v1 — all endpoints live under /api/v1/hellom (names api.v1.hellom.*).
| Each module file is required in a fixed order: registration order decides
| which route matches first, so do not reorder without checking route:list.
*/

// Uptime monitor: database, cache, scheduler heartbeat, queue backlog (200 / 503).
Route::get('/health', HealthController::class)->middleware('throttle:60,1')->name('api.health');

Route::prefix('v1/hellom')->name('api.v1.hellom.')->group(function () {
    require __DIR__.'/api/public.php';

    Route::middleware([AuthenticateApiToken::class])->group(function () {
        require __DIR__.'/api/account.php';
        require __DIR__.'/api/wallet.php';
        require __DIR__.'/api/billing.php';
        require __DIR__.'/api/consumer.php';
        require __DIR__.'/api/landing-builder.php';
        require __DIR__.'/api/member.php';
        require __DIR__.'/api/pos.php';
        require __DIR__.'/api/admin.php';
    });
});
