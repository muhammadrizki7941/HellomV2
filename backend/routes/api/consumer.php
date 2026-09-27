<?php

/*
| Consumer notifications, digital products & onboarding.
| Loaded from routes/api.php inside the v1/hellom prefix group.
*/

use App\Http\Controllers\Api\V1\Consumer\NotificationController as ConsumerNotificationController;
use App\Http\Controllers\Api\V1\Consumer\OnboardingController as ConsumerOnboardingController;
use App\Http\Controllers\Api\V1\Consumer\ProductController as ConsumerProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('consumer/notifications')->group(function () {
    Route::get('/', [ConsumerNotificationController::class, 'index']);
    Route::get('/unread-count', [ConsumerNotificationController::class, 'unreadCount']);
    Route::post('/read-all', [ConsumerNotificationController::class, 'markAllRead']);
    Route::post('/{id}/read', [ConsumerNotificationController::class, 'markRead']);
});

Route::prefix('consumer')->group(function () {
    Route::get('/products', [ConsumerProductController::class, 'index']);
    Route::get('/products/{slug}', [ConsumerProductController::class, 'show']);
    Route::post('/products/{id}/purchase', [ConsumerProductController::class, 'purchase']);
    Route::get('/products/{id}/purchase/status', [ConsumerProductController::class, 'purchaseStatus']);
    Route::post('/products/{id}/purchase/cancel', [ConsumerProductController::class, 'cancelPurchase']);
    Route::post('/products/{id}/download/{fileId}', [ConsumerProductController::class, 'download']);
    Route::get('/products/{id}/docs/{docId}/preview', [ConsumerProductController::class, 'previewDoc']);
    Route::get('/my-purchases', [ConsumerProductController::class, 'myPurchases']);

    Route::get('/onboarding/tips', [ConsumerOnboardingController::class, 'tips']);
    Route::post('/onboarding/dismiss', [ConsumerOnboardingController::class, 'dismiss']);
});
