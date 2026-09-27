<?php

/*
| Product purchase settings, promo validation, member invoices, locale switch.
| Loaded from routes/api.php inside the v1/hellom prefix group.
*/

use App\Http\Controllers\Api\V1\Hellom\InvoiceController;
use App\Http\Controllers\Api\V1\Hellom\ProductPurchaseSettingController;
use App\Http\Controllers\Api\V1\Hellom\PromoCampaignController;
use Illuminate\Support\Facades\Route;

// ─── Product Purchase Settings ───
Route::prefix('purchase-settings')->name('purchase_settings.')->middleware('canUseApp:pos')->group(function () {
    Route::get('/', [ProductPurchaseSettingController::class, 'index'])->name('index');
    Route::get('/active', [ProductPurchaseSettingController::class, 'getActive'])->name('active');
    Route::post('/', [ProductPurchaseSettingController::class, 'store'])->name('store');
    Route::put('/{id}', [ProductPurchaseSettingController::class, 'update'])->name('update');
    Route::delete('/{id}', [ProductPurchaseSettingController::class, 'destroy'])->name('destroy');
});

// ─── Promo: Validate ───
Route::post('/promo/validate', [PromoCampaignController::class, 'validateCode'])->name('promo.validate');

// ─── Invoices: Member ───
Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
Route::get('/invoices/{id}', [InvoiceController::class, 'show'])->name('invoices.show');

// ─── Locale ───
Route::post('/locale/switch', function (\Illuminate\Http\Request $request) {
    $validated = $request->validate(['locale' => ['required', 'in:id,en']]);
    $user = $request->user();
    if ($user instanceof \App\Models\User) {
        $user->forceFill(['locale' => $validated['locale']])->save();
    }
    session(['locale' => $validated['locale']]);
    return response()->json([
        'success' => true,
        'message' => 'Locale switched',
        'data' => ['locale' => $validated['locale']],
        'error' => null,
    ]);
})->name('locale.switch');
