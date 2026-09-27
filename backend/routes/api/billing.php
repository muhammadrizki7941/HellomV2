<?php

/*
| Entitlements, app catalog, pricing, subscription checkout & wallet billing.
| Loaded from routes/api.php inside the v1/hellom prefix group.
*/

use App\Http\Controllers\Api\V1\Hellom\AppCatalogController;
use App\Http\Controllers\Api\V1\Hellom\BillingController;
use App\Http\Controllers\Api\V1\Hellom\EntitlementController;
use App\Http\Controllers\Api\V1\Hellom\MemberDashboardController;
use App\Http\Controllers\Api\V1\Hellom\PricingController;
use Illuminate\Support\Facades\Route;

Route::get('/entitlements', [EntitlementController::class, 'index'])->name('entitlements.index');
Route::get('/entitlements/check/{slug}', [EntitlementController::class, 'check'])->name('entitlements.check');
Route::get('/member/dashboard/cards', [MemberDashboardController::class, 'cards'])->name('member.dashboard.cards');
Route::get('/catalog/apps', [AppCatalogController::class, 'index'])->name('catalog.apps.index');
Route::get('/catalog/apps/{slug}', [AppCatalogController::class, 'show'])->name('catalog.apps.show');
Route::get('/pricing/matrix', [PricingController::class, 'matrix'])->name('pricing.matrix');
Route::post('/pricing/preview-upgrade', [PricingController::class, 'previewUpgrade'])->name('pricing.preview_upgrade');
Route::get('/billing/overview', [BillingController::class, 'overview'])->name('billing.overview');
Route::get('/billing/history', [BillingController::class, 'history'])->name('billing.history');
Route::get('/billing/gateway-status', [BillingController::class, 'gatewayStatus'])->name('billing.gateway_status');
Route::get('/billing/runtime-config', [BillingController::class, 'checkoutRuntimeConfig'])->name('billing.runtime_config');
Route::post('/billing/checkout-start', [BillingController::class, 'checkoutStart'])->name('billing.checkout_start');
Route::post('/billing/checkout-reconcile', [BillingController::class, 'reconcileCheckout'])->name('billing.checkout_reconcile');
Route::post('/billing/subscriptions/{subscriptionId}/renew-mock', [BillingController::class, 'renewSubscriptionMock'])->middleware('billing.mock')->name('billing.subscriptions.renew_mock');
Route::post('/billing/subscriptions/{subscriptionId}/renew-wallet', [BillingController::class, 'renewSubscriptionWallet'])->name('billing.subscriptions.renew_wallet');
Route::post('/billing/subscriptions/{subscriptionId}/auto-renew-wallet', [BillingController::class, 'setSubscriptionWalletAutoRenew'])->name('billing.subscriptions.auto_renew_wallet');
Route::post('/billing/checkout-intent-mock', [BillingController::class, 'checkoutIntentMock'])->middleware('billing.mock')->name('billing.checkout_intent_mock');
Route::post('/billing/checkout-confirm-mock', [BillingController::class, 'checkoutConfirmMock'])->middleware('billing.mock')->name('billing.checkout_confirm_mock');
Route::post('/billing/checkout-confirm-wallet', [BillingController::class, 'checkoutConfirmWallet'])->name('billing.checkout_confirm_wallet');
Route::post('/billing/wallet/topup-session', [BillingController::class, 'walletTopupSession'])->name('billing.wallet.topup_session');
Route::post('/billing/wallet/topup-mock', [BillingController::class, 'walletTopupMock'])->middleware('billing.mock')->name('billing.wallet.topup_mock');
Route::get('/billing/wallet/auto-renew-preview', [BillingController::class, 'walletAutoRenewPreview'])->name('billing.wallet.auto_renew_preview');
