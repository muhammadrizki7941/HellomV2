<?php

/*
| Kalkulator HPP (dashboard app "hpp", free; Pro features need a POS or hpp_* subscription).
| Loaded from routes/api.php inside the authenticated v1/hellom group. Owner/admin checked in
| HppBaseController.
*/

use App\Http\Controllers\Api\V1\Hellom\Hpp\HppCostController;
use App\Http\Controllers\Api\V1\Hellom\Hpp\HppIngredientController;
use App\Http\Controllers\Api\V1\Hellom\Hpp\HppPurchaseController;
use App\Http\Controllers\Api\V1\Hellom\Hpp\HppReportController;
use App\Http\Controllers\Api\V1\Hellom\Hpp\HppProductController;
use App\Http\Controllers\Api\V1\Hellom\Hpp\HppSetupController;
use Illuminate\Support\Facades\Route;

Route::prefix('hpp')->name('hpp.')->group(function () {
    Route::get('/overview', [HppSetupController::class, 'overview'])->name('overview');
    Route::put('/settings', [HppSetupController::class, 'updateSettings'])->name('settings');
    Route::post('/onboarding', [HppSetupController::class, 'onboard'])->name('onboarding');

    Route::get('/ingredients', [HppIngredientController::class, 'index'])->name('ingredients.index');
    Route::post('/ingredients', [HppIngredientController::class, 'store'])->name('ingredients.store');
    Route::put('/ingredients/{id}', [HppIngredientController::class, 'update'])->whereNumber('id')->name('ingredients.update');
    Route::delete('/ingredients/{id}', [HppIngredientController::class, 'destroy'])->whereNumber('id')->name('ingredients.destroy');

    Route::get('/products', [HppProductController::class, 'index'])->name('products.index');
    Route::post('/products', [HppProductController::class, 'store'])->name('products.store');
    Route::get('/products/{id}', [HppProductController::class, 'show'])->whereNumber('id')->name('products.show');
    Route::put('/products/{id}', [HppProductController::class, 'update'])->whereNumber('id')->name('products.update');
    Route::delete('/products/{id}', [HppProductController::class, 'destroy'])->whereNumber('id')->name('products.destroy');
    Route::post('/products/{id}/apply-price', [HppProductController::class, 'applyPrice'])->whereNumber('id')->name('products.apply_price');
    Route::get('/pos-products', [HppProductController::class, 'posProducts'])->name('pos_products');
    Route::post('/products/import-pos', [HppProductController::class, 'importPos'])->name('products.import_pos');

    // Tahap 2: biaya tetap, channel, pembelian, laporan.
    Route::get('/costs', [HppCostController::class, 'index'])->name('costs.index');
    Route::post('/overheads', [HppCostController::class, 'storeOverhead'])->name('overheads.store');
    Route::post('/overheads/template', [HppCostController::class, 'loadTemplate'])->name('overheads.template');
    Route::put('/overheads/{id}', [HppCostController::class, 'updateOverhead'])->whereNumber('id')->name('overheads.update');
    Route::delete('/overheads/{id}', [HppCostController::class, 'destroyOverhead'])->whereNumber('id')->name('overheads.destroy');
    Route::post('/channels', [HppCostController::class, 'storeChannel'])->name('channels.store');
    Route::put('/channels/{id}', [HppCostController::class, 'updateChannel'])->whereNumber('id')->name('channels.update');
    Route::delete('/channels/{id}', [HppCostController::class, 'destroyChannel'])->whereNumber('id')->name('channels.destroy');
    Route::get('/purchases', [HppPurchaseController::class, 'index'])->name('purchases.index');
    Route::post('/purchases', [HppPurchaseController::class, 'store'])->name('purchases.store');
    Route::get('/reports/products', [HppReportController::class, 'products'])->name('reports.products');
});
