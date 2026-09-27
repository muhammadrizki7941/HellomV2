<?php

// Cashier Routes for Single Restaurant App

use App\Http\Controllers\Cashier\CashierHomeController;
use App\Http\Controllers\Cashier\CashierLoginController;
use App\Http\Controllers\Cashier\CashierOrderController;
use Illuminate\Support\Facades\Route;

// Cashier Login (separate from admin)
Route::get('/cashier/login', [CashierLoginController::class, 'showLoginForm'])->name('cashier.login');
Route::post('/cashier/login', [CashierLoginController::class, 'login'])->name('cashier.login.submit');
Route::post('/cashier/logout', [CashierLoginController::class, 'logout'])->name('cashier.logout');

// Cashier App
Route::prefix('cashier')->middleware(['auth:cashier'])->group(function () {
    Route::get('/', [CashierHomeController::class, 'index'])->name('cashier.index');
    
    // Orders
    Route::get('/orders', [CashierOrderController::class, 'index'])->name('cashier.orders.index');
    Route::get('/orders/{orderNumber}', [CashierOrderController::class, 'show'])->name('cashier.orders.show');
    Route::patch('/orders/{orderNumber}/status', [CashierOrderController::class, 'updateStatus'])->name('cashier.orders.updateStatus');
});
