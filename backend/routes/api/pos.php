<?php

/*
| POS app (requires canUseApp:pos + InjectPosContext outlet scoping).
| Loaded from routes/api.php inside the v1/hellom prefix group.
*/

use App\Http\Controllers\Api\V1\Hellom\EntitlementController;
use App\Http\Controllers\Api\V1\Hellom\OrderController as HellomOrderController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosCategoryController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosExperienceController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosLoyaltyController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosMemberController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosOrderController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosOutletController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosPaymentSettingController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosProductController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosReportController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosStaffController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosTableController;
use Illuminate\Support\Facades\Route;

// ─── POS Routes ───
Route::middleware(['canUseApp:pos', 'App\Http\Middleware\Api\InjectPosContext'])->group(function () {
    // Outlet management (multi-outlet per organization)
    Route::get('/pos/outlets', [PosOutletController::class, 'index']);
    Route::post('/pos/outlets', [PosOutletController::class, 'store']);
    Route::patch('/pos/outlets/{outletId}', [PosOutletController::class, 'update']);
    Route::delete('/pos/outlets/{outletId}', [PosOutletController::class, 'destroy']);

    Route::get('/pos/orders', [PosOrderController::class, 'index']);
    Route::post('/pos/orders', [PosOrderController::class, 'store']);
    Route::patch('/pos/orders/{orderId}/status', [PosOrderController::class, 'updateStatus']);
    Route::post('/pos/orders/{orderId}/payment', [HellomOrderController::class, 'confirmPayment']);
    Route::get('/pos/orders/{orderId}/receipt', [PosOrderController::class, 'receipt']);
    Route::get('/pos/products', [PosProductController::class, 'index']);
    Route::post('/pos/products', [PosProductController::class, 'store']);
    Route::post('/pos/products/{productId}', [PosProductController::class, 'update']); // For FormData with _method spoofing
    Route::patch('/pos/products/{productId}', [PosProductController::class, 'update']);
    Route::delete('/pos/products/{productId}', [PosProductController::class, 'destroy']);

    Route::get('/pos/categories', [PosCategoryController::class, 'index']);
    Route::post('/pos/categories', [PosCategoryController::class, 'store']);
    Route::patch('/pos/categories/{categoryId}', [PosCategoryController::class, 'update']);
    Route::delete('/pos/categories/{categoryId}', [PosCategoryController::class, 'destroy']);

    Route::get('/pos/tables', [PosTableController::class, 'index']);
    Route::post('/pos/tables', [PosTableController::class, 'store']);
    Route::patch('/pos/tables/{tableId}', [PosTableController::class, 'update']);
    Route::delete('/pos/tables/{tableId}', [PosTableController::class, 'destroy']);

    Route::get('/pos/payment-settings', [PosPaymentSettingController::class, 'index']);
    Route::post('/pos/payment-settings', [PosPaymentSettingController::class, 'update']);

    // Member management
    Route::prefix('pos/members')->group(function () {
        Route::get('/', [PosMemberController::class, 'index']);
        Route::get('/search', [PosMemberController::class, 'search']);
        Route::post('/', [PosMemberController::class, 'store']);
        Route::get('/{id}', [PosMemberController::class, 'show']);
        Route::put('/{id}', [PosMemberController::class, 'update']);
        Route::get('/{id}/points', [PosMemberController::class, 'pointHistory']);
    });

    // Loyalty
    Route::prefix('pos/loyalty')->group(function () {
        Route::post('/calculate', [PosLoyaltyController::class, 'calculatePoints']);
        Route::post('/apply-reward', [PosLoyaltyController::class, 'applyReward']);
        Route::get('/settings', [PosLoyaltyController::class, 'getSettings']);
        Route::put('/settings', [PosLoyaltyController::class, 'updateSettings']);
        Route::get('/reward-rules', [PosLoyaltyController::class, 'rewardRules']);
        Route::post('/reward-rules', [PosLoyaltyController::class, 'storeRewardRule']);
        Route::put('/reward-rules/{id}', [PosLoyaltyController::class, 'updateRewardRule']);
        Route::delete('/reward-rules/{id}', [PosLoyaltyController::class, 'deleteRewardRule']);
    });

    Route::prefix('pos/customer-experience')->group(function () {
        Route::get('/dashboard', [PosExperienceController::class, 'dashboard']);
        Route::post('/promos', [PosExperienceController::class, 'storePromo']);
        Route::post('/promos/{id}', [PosExperienceController::class, 'updatePromo']);
        Route::delete('/promos/{id}', [PosExperienceController::class, 'destroyPromo']);
        Route::post('/spaces', [PosExperienceController::class, 'storeSpace']);
        Route::post('/spaces/{id}', [PosExperienceController::class, 'updateSpace']);
        Route::delete('/spaces/{id}', [PosExperienceController::class, 'destroySpace']);
        Route::patch('/reservations/{id}/status', [PosExperienceController::class, 'updateReservationStatus']);
    });

    Route::prefix('pos/reports')->group(function () {
        Route::get('/summary', [PosReportController::class, 'summary']);
        Route::get('/products', [PosReportController::class, 'products']);
        Route::get('/daily', [PosReportController::class, 'daily']);
        Route::get('/export', [PosReportController::class, 'export']);
    });

    Route::prefix('pos/staff')->group(function () {
        Route::get('/', [PosStaffController::class, 'index']);
        Route::post('/', [PosStaffController::class, 'store']);
        Route::post('/attendance/scan', [PosStaffController::class, 'scanAttendanceQr']);
        Route::put('/{staffId}', [PosStaffController::class, 'update']);
        Route::post('/{staffId}/invite-login', [PosStaffController::class, 'inviteLogin']);
        Route::delete('/{staffId}', [PosStaffController::class, 'destroy']);
        Route::get('/{staffId}/attendance-qr', [PosStaffController::class, 'showAttendanceQr']);
        Route::post('/{staffId}/attendance-qr/regenerate', [PosStaffController::class, 'regenerateAttendanceQr']);
        Route::post('/shifts', [PosStaffController::class, 'storeShift']);
        Route::put('/shifts/{shiftId}', [PosStaffController::class, 'updateShift']);
        Route::post('/{staffId}/attendance/check-in', [PosStaffController::class, 'checkIn']);
        Route::post('/{staffId}/attendance/check-out', [PosStaffController::class, 'checkOut']);
        Route::post('/{staffId}/attendance/leave', [PosStaffController::class, 'markLeave']);
        Route::post('/{staffId}/cash/open', [PosStaffController::class, 'openCash']);
        Route::post('/{staffId}/cash/close', [PosStaffController::class, 'closeCash']);
        Route::get('/export/download', [PosStaffController::class, 'export']);
    });

    Route::get('/apps/pos/probe', [EntitlementController::class, 'probeLocked'])
        ->name('apps.pos.probe');
    Route::get('/apps/pos/access', [EntitlementController::class, 'posAccess'])
        ->name('apps.pos.access');
});
