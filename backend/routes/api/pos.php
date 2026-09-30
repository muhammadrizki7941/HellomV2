<?php

/*
| POS app (requires canUseApp:pos + InjectPosContext outlet scoping).
| Loaded from routes/api.php inside the v1/hellom prefix group.
*/

use App\Http\Controllers\Api\V1\Hellom\EntitlementController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosCategoryController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosExperienceController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosLoyaltyController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosMemberController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosOrderController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosOutletController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosOutletSettingsController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosPaymentSettingController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosProductController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosReportController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosStaffController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosTableController;
use Illuminate\Support\Facades\Route;

// ─── POS Routes ───
Route::middleware(['canUseApp:pos', 'App\Http\Middleware\Api\InjectPosContext'])->group(function () {
    // Outlet management (multi-outlet per organization)
    Route::get('/pos/outlets', [PosOutletController::class, 'index'])->middleware('posPermission:orders');
    Route::post('/pos/outlets', [PosOutletController::class, 'store'])->middleware('posPermission:manager');
    Route::patch('/pos/outlets/{outletId}', [PosOutletController::class, 'update'])->middleware('posPermission:manager');
    Route::delete('/pos/outlets/{outletId}', [PosOutletController::class, 'destroy'])->middleware('posPermission:manager');

    // Orders: one engine (OrderService) for cashier and self-order. Payment is separate from kitchen status.
    Route::get('/pos/orders', [PosOrderController::class, 'index'])->middleware('posPermission:orders');
    Route::post('/pos/orders', [PosOrderController::class, 'store'])->middleware('posPermission:orders');
    Route::post('/pos/orders/preview', [PosOrderController::class, 'preview'])->middleware('posPermission:orders');
    Route::patch('/pos/orders/{orderId}/status', [PosOrderController::class, 'updateStatus'])->middleware('posPermission:orders');
    Route::post('/pos/orders/{orderId}/payment', [PosOrderController::class, 'pay'])->middleware('posPermission:orders');
    Route::post('/pos/orders/{orderId}/cancel', [PosOrderController::class, 'cancel'])->middleware('posPermission:order_cancel');
    Route::post('/pos/orders/{orderId}/refund', [PosOrderController::class, 'refund'])->middleware('posPermission:order_refund');
    Route::get('/pos/orders/{orderId}/receipt', [PosOrderController::class, 'receipt'])->middleware('posPermission:orders');
    Route::get('/pos/table-bills', [PosOrderController::class, 'tableBills'])->middleware('posPermission:orders');
    Route::get('/pos/table-bills/{billId}', [PosOrderController::class, 'showTableBill'])->middleware('posPermission:orders');
    Route::post('/pos/table-bills/{billId}/pay', [PosOrderController::class, 'payTableBill'])->middleware('posPermission:orders');
    Route::get('/pos/realtime/token', [PosOrderController::class, 'realtimeToken'])->middleware('posPermission:orders');
    Route::get('/pos/products', [PosProductController::class, 'index'])->middleware('posPermission:orders');
    Route::post('/pos/products', [PosProductController::class, 'store'])->middleware('posPermission:products');
    Route::post('/pos/products/{productId}', [PosProductController::class, 'update'])->middleware('posPermission:products'); // For FormData with _method spoofing
    Route::patch('/pos/products/{productId}', [PosProductController::class, 'update'])->middleware('posPermission:products');
    Route::delete('/pos/products/{productId}', [PosProductController::class, 'destroy'])->middleware('posPermission:products');

    Route::get('/pos/categories', [PosCategoryController::class, 'index'])->middleware('posPermission:orders');
    Route::post('/pos/categories', [PosCategoryController::class, 'store'])->middleware('posPermission:products');
    Route::patch('/pos/categories/{categoryId}', [PosCategoryController::class, 'update'])->middleware('posPermission:products');
    Route::delete('/pos/categories/{categoryId}', [PosCategoryController::class, 'destroy'])->middleware('posPermission:products');

    Route::get('/pos/tables', [PosTableController::class, 'index'])->middleware('posPermission:orders');
    Route::post('/pos/tables', [PosTableController::class, 'store'])->middleware('posPermission:tables');
    Route::patch('/pos/tables/{tableId}', [PosTableController::class, 'update'])->middleware('posPermission:tables');
    Route::delete('/pos/tables/{tableId}', [PosTableController::class, 'destroy'])->middleware('posPermission:tables');
    Route::get('/pos/tables-qr-sheet', [PosTableController::class, 'qrSheet'])->middleware('posPermission:tables');
    Route::post('/pos/tables/{tableId}/regenerate-token', [PosTableController::class, 'regenerateToken'])->middleware('posPermission:tables');

    // Active outlet: tax/service/rounding, self-order behaviour, opening hours.
    Route::get('/pos/outlet-settings', [PosOutletSettingsController::class, 'show'])->middleware('posPermission:orders');
    Route::put('/pos/outlet-settings', [PosOutletSettingsController::class, 'update'])->middleware('posPermission:outlet_settings');

    Route::get('/pos/payment-settings', [PosPaymentSettingController::class, 'index'])->middleware('posPermission:manager');
    Route::post('/pos/payment-settings', [PosPaymentSettingController::class, 'update'])->middleware('posPermission:manager');

    // Member management
    Route::prefix('pos/members')->group(function () {
        Route::get('/', [PosMemberController::class, 'index'])->middleware('posPermission:members');
        Route::get('/search', [PosMemberController::class, 'search'])->middleware('posPermission:orders');
        Route::get('/export', [PosMemberController::class, 'export'])->middleware('posPermission:member_points');
        Route::get('/duplicates', [PosMemberController::class, 'duplicates'])->middleware('posPermission:member_points');
        Route::post('/merge', [PosMemberController::class, 'merge'])->middleware('posPermission:member_points');
        Route::get('/fraud-flags', [PosMemberController::class, 'fraudFlags'])->middleware('posPermission:member_points');
        Route::patch('/fraud-flags/{flagId}', [PosMemberController::class, 'resolveFraudFlag'])->middleware('posPermission:member_points');
        Route::post('/', [PosMemberController::class, 'store'])->middleware('posPermission:orders');
        Route::get('/{id}', [PosMemberController::class, 'show'])->middleware('posPermission:members');
        Route::put('/{id}', [PosMemberController::class, 'update'])->middleware('posPermission:members');
        Route::get('/{id}/points', [PosMemberController::class, 'pointHistory'])->middleware('posPermission:members');
        Route::get('/{id}/orders', [PosMemberController::class, 'orders'])->middleware('posPermission:members');
        Route::post('/{id}/adjust-points', [PosMemberController::class, 'adjustPoints'])->middleware('posPermission:member_points');
    });

    // Loyalty
    Route::prefix('pos/loyalty')->group(function () {
        Route::post('/calculate', [PosLoyaltyController::class, 'calculatePoints'])->middleware('posPermission:orders');
        Route::post('/apply-reward', [PosLoyaltyController::class, 'applyReward'])->middleware('posPermission:orders');
        Route::get('/settings', [PosLoyaltyController::class, 'getSettings'])->middleware('posPermission:orders');
        Route::put('/settings', [PosLoyaltyController::class, 'updateSettings'])->middleware('posPermission:loyalty');
        Route::get('/reward-rules', [PosLoyaltyController::class, 'rewardRules'])->middleware('posPermission:orders');
        Route::post('/reward-rules', [PosLoyaltyController::class, 'storeRewardRule'])->middleware('posPermission:loyalty');
        Route::put('/reward-rules/{id}', [PosLoyaltyController::class, 'updateRewardRule'])->middleware('posPermission:loyalty');
        Route::delete('/reward-rules/{id}', [PosLoyaltyController::class, 'deleteRewardRule'])->middleware('posPermission:loyalty');
    });

    Route::prefix('pos/customer-experience')->group(function () {
        Route::get('/dashboard', [PosExperienceController::class, 'dashboard'])->middleware('posPermission:customer_hub');
        Route::post('/promos', [PosExperienceController::class, 'storePromo'])->middleware('posPermission:customer_hub');
        Route::post('/promos/{id}', [PosExperienceController::class, 'updatePromo'])->middleware('posPermission:customer_hub');
        Route::delete('/promos/{id}', [PosExperienceController::class, 'destroyPromo'])->middleware('posPermission:customer_hub');
        Route::post('/spaces', [PosExperienceController::class, 'storeSpace'])->middleware('posPermission:customer_hub');
        Route::post('/spaces/{id}', [PosExperienceController::class, 'updateSpace'])->middleware('posPermission:customer_hub');
        Route::delete('/spaces/{id}', [PosExperienceController::class, 'destroySpace'])->middleware('posPermission:customer_hub');
        Route::patch('/reservations/{id}/status', [PosExperienceController::class, 'updateReservationStatus'])->middleware('posPermission:customer_hub');
    });

    Route::prefix('pos/reports')->group(function () {
        Route::get('/summary', [PosReportController::class, 'summary'])->middleware('posPermission:reports');
        Route::get('/products', [PosReportController::class, 'products'])->middleware('posPermission:reports');
        Route::get('/daily', [PosReportController::class, 'daily'])->middleware('posPermission:reports');
        Route::get('/export', [PosReportController::class, 'export'])->middleware('posPermission:reports');
    });

    Route::prefix('pos/staff')->group(function () {
        Route::get('/', [PosStaffController::class, 'index'])->middleware('posPermission:manager');
        Route::post('/', [PosStaffController::class, 'store'])->middleware('posPermission:manager');
        Route::post('/attendance/scan', [PosStaffController::class, 'scanAttendanceQr'])->middleware('posPermission:manager');
        Route::put('/{staffId}', [PosStaffController::class, 'update'])->middleware('posPermission:manager');
        Route::post('/{staffId}/invite-login', [PosStaffController::class, 'inviteLogin'])->middleware('posPermission:manager');
        Route::delete('/{staffId}', [PosStaffController::class, 'destroy'])->middleware('posPermission:manager');
        Route::get('/{staffId}/attendance-qr', [PosStaffController::class, 'showAttendanceQr'])->middleware('posPermission:manager');
        Route::post('/{staffId}/attendance-qr/regenerate', [PosStaffController::class, 'regenerateAttendanceQr'])->middleware('posPermission:manager');
        Route::post('/shifts', [PosStaffController::class, 'storeShift'])->middleware('posPermission:manager');
        Route::put('/shifts/{shiftId}', [PosStaffController::class, 'updateShift'])->middleware('posPermission:manager');
        Route::post('/{staffId}/attendance/check-in', [PosStaffController::class, 'checkIn'])->middleware('posPermission:manager');
        Route::post('/{staffId}/attendance/check-out', [PosStaffController::class, 'checkOut'])->middleware('posPermission:manager');
        Route::post('/{staffId}/attendance/leave', [PosStaffController::class, 'markLeave'])->middleware('posPermission:manager');
        Route::post('/{staffId}/cash/open', [PosStaffController::class, 'openCash'])->middleware('posPermission:cash_control');
        Route::post('/{staffId}/cash/close', [PosStaffController::class, 'closeCash'])->middleware('posPermission:cash_control');
        Route::get('/export/download', [PosStaffController::class, 'export'])->middleware('posPermission:manager');
    });

    // The signed-in cashier's current permissions (the POS menu refreshes from this).
    Route::get('/pos/me/access', [PosStaffController::class, 'myAccess']);
    Route::get('/pos/me/cash', [PosStaffController::class, 'myCash'])->middleware('posPermission:cash_control');
    Route::get('/apps/pos/probe', [EntitlementController::class, 'probeLocked'])
        ->name('apps.pos.probe');
    Route::get('/apps/pos/access', [EntitlementController::class, 'posAccess'])
        ->name('apps.pos.access');
});
