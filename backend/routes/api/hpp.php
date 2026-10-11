<?php

/*
| Kalkulator HPP (dashboard app "hpp", free; Pro features need a POS or hpp_* subscription).
| Loaded from routes/api.php inside the authenticated v1/hellom group. Owner/admin checked in
| HppBaseController.
*/

use App\Http\Controllers\Api\V1\Hellom\Hpp\HppIngredientController;
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
});
