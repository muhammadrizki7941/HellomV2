<?php

/*
| Organization wallet, withdrawals (review = super admin), payout/KYC profile, platform finance.
| Loaded from routes/api.php inside the v1/hellom prefix group.
*/

use App\Http\Controllers\Api\V1\Hellom\PayoutProfileController;
use App\Http\Controllers\Api\V1\Hellom\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/wallet/overview', [WalletController::class, 'overview'])->name('wallet.overview');
Route::get('/wallet/payout-policy', [WalletController::class, 'payoutPolicy'])->name('wallet.payout_policy');
Route::get('/wallet/finance-summary', [WalletController::class, 'financeSummary'])->name('wallet.finance_summary');
Route::get('/wallet/admin/payout-queue', [WalletController::class, 'adminPayoutQueue'])->middleware('superAdmin')->name('wallet.admin.payout_queue');
Route::get('/wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions');
Route::get('/wallet/payout-history', [WalletController::class, 'payoutHistory'])->name('wallet.payout_history');
Route::get('/wallet/withdrawals', [WalletController::class, 'withdrawals'])->name('wallet.withdrawals');
Route::post('/wallet/withdrawals', [WalletController::class, 'requestWithdrawal'])->name('wallet.withdrawals.request');
Route::post('/wallet/withdrawals/{withdrawalId}/approve', [WalletController::class, 'approveWithdrawal'])->middleware('superAdmin')->name('wallet.withdrawals.approve');
Route::post('/wallet/withdrawals/{withdrawalId}/reject', [WalletController::class, 'rejectWithdrawal'])->middleware('superAdmin')->name('wallet.withdrawals.reject');
Route::post('/wallet/withdrawals/{withdrawalId}/mark-paid', [WalletController::class, 'markWithdrawalPaid'])->middleware('superAdmin')->name('wallet.withdrawals.mark_paid');
Route::post('/wallet/withdrawals/{withdrawalId}/mark-failed', [WalletController::class, 'markWithdrawalFailed'])->middleware('superAdmin')->name('wallet.withdrawals.mark_failed');
Route::post('/wallet/withdrawals/{withdrawalId}/cancel', [WalletController::class, 'cancelWithdrawal'])->name('wallet.withdrawals.cancel');

// Payout / KYC profile (KTP + bank) required before withdrawal
Route::get('/payout-profile', [PayoutProfileController::class, 'show'])->name('payout_profile.show');
Route::post('/payout-profile', [PayoutProfileController::class, 'submit'])->name('payout_profile.submit');

// Super-admin KYC review queue
Route::get('/admin/payout-profiles', [PayoutProfileController::class, 'adminIndex'])->name('admin.payout_profiles.index');
Route::get('/admin/payout-profiles/{profileId}/ktp', [PayoutProfileController::class, 'ktpImage'])->name('admin.payout_profiles.ktp');
Route::post('/admin/payout-profiles/{profileId}/approve', [PayoutProfileController::class, 'approve'])->name('admin.payout_profiles.approve');
Route::post('/admin/payout-profiles/{profileId}/reject', [PayoutProfileController::class, 'reject'])->name('admin.payout_profiles.reject');

// Platform finance (super admin only)
Route::get('/platform/finance-summary', [WalletController::class, 'platformFinanceSummary'])->name('platform.finance_summary');
Route::post('/platform/payouts', [WalletController::class, 'createPlatformPayout'])->name('platform.payouts.create');
