<?php

/*
| Super admin (prefix admin/, name admin., middleware superAdmin).
| Loaded from routes/api.php inside the v1/hellom prefix group.
*/

use App\Http\Controllers\Admin\DigitalProductController as AdminDigitalProductController;
use App\Http\Controllers\Admin\ProductPurchaseController;
use App\Http\Controllers\Api\V1\Hellom\AdminMailController;
use App\Http\Controllers\Api\V1\Hellom\BannerController;
use App\Http\Controllers\Api\V1\Hellom\Billing\ManualCheckoutReviewController;
use App\Http\Controllers\Api\V1\Hellom\Billing\PaymentGatewayConfigController;
use App\Http\Controllers\Api\V1\Hellom\BrandSettingController;
use App\Http\Controllers\Api\V1\Hellom\InvoiceController;
use App\Http\Controllers\Api\V1\Hellom\LandingContentController;
use App\Http\Controllers\Api\V1\Hellom\PromoCampaignController;
use App\Http\Controllers\Api\V1\Hellom\ShowcaseController;
use App\Http\Controllers\Api\V1\Hellom\SuperAdminController;
use App\Http\Controllers\Api\V1\Hellom\AdminLandingModerationController;
use App\Http\Controllers\Api\V1\Hellom\AdminSellerFinanceController;
use Illuminate\Support\Facades\Route;

// ─── AUTH + superAdmin ───
Route::prefix('admin')->name('admin.')->middleware('superAdmin')->group(function () {
    Route::get('/dashboard-stats', [SuperAdminController::class, 'dashboardStats'])->name('dashboard_stats');

    Route::get('/organizations', [SuperAdminController::class, 'listOrganizations'])->name('organizations.index');
    Route::get('/organizations/{organizationId}', [SuperAdminController::class, 'showOrganization'])->name('organizations.show');
    Route::post('/organizations/{organizationId}/suspend', [SuperAdminController::class, 'suspendOrganization'])->name('organizations.suspend');
    Route::post('/organizations/{organizationId}/reactivate', [SuperAdminController::class, 'reactivateOrganization'])->name('organizations.reactivate');
    Route::patch('/organizations/{organizationId}/outlet-limit', [SuperAdminController::class, 'updateOrganizationOutletLimit'])->name('organizations.outlet_limit');

    Route::get('/users', [SuperAdminController::class, 'listUsers'])->name('users.index');
    Route::get('/users/{userId}', [SuperAdminController::class, 'showUser'])->name('users.show');
    Route::post('/users/{userId}/suspend', [SuperAdminController::class, 'suspendUser'])->name('users.suspend');
    Route::post('/users/{userId}/reactivate', [SuperAdminController::class, 'reactivateUser'])->name('users.reactivate');
    Route::delete('/users/{userId}', [SuperAdminController::class, 'deleteUser'])->name('users.destroy');
    Route::put('/users/{userId}/app-access', [SuperAdminController::class, 'updateUserAppAccess'])->name('users.app_access.update');

    Route::get('/apps', [SuperAdminController::class, 'listApps'])->name('apps.index');
    Route::put('/apps/{appId}', [SuperAdminController::class, 'updateApp'])->name('apps.update');

    Route::get('/plans', [SuperAdminController::class, 'listPlans'])->name('plans.index');
    Route::post('/plans', [SuperAdminController::class, 'createPlan'])->name('plans.store');
    Route::put('/plans/{planId}', [SuperAdminController::class, 'updatePlan'])->name('plans.update');
    Route::delete('/plans/{planId}', [SuperAdminController::class, 'deletePlan'])->name('plans.destroy');
    Route::get('/plans/{planId}/subscriptions', [SuperAdminController::class, 'planSubscriptions'])->name('plans.subscriptions');

    Route::post('/entitlements/override', [SuperAdminController::class, 'overrideEntitlement'])->name('entitlements.override');

    Route::get('/audit-logs', [SuperAdminController::class, 'auditLogs'])->name('audit_logs');
    Route::put('/billing/runtime-config', [PaymentGatewayConfigController::class, 'updateCheckoutRuntimeConfig'])->name('billing.runtime_config.update');
    Route::get('/billing/provider-config', [PaymentGatewayConfigController::class, 'adminGatewayConfig'])->name('billing.provider_config');
    Route::put('/billing/provider-config', [PaymentGatewayConfigController::class, 'updateAdminGatewayConfig'])->name('billing.provider_config.update');
    Route::post('/billing/provider-config/ipaymu/reset', [PaymentGatewayConfigController::class, 'resetIpaymuConfig'])->name('billing.provider_config.ipaymu.reset');
    Route::get('/billing/manual-payment-config', [PaymentGatewayConfigController::class, 'adminManualPaymentConfig'])->name('billing.manual_payment_config');
    Route::post('/billing/manual-payment-config', [PaymentGatewayConfigController::class, 'updateAdminManualPaymentConfig'])->name('billing.manual_payment_config.update');
    Route::get('/billing/manual-checkouts', [ManualCheckoutReviewController::class, 'adminPendingCheckouts'])->name('billing.manual_checkouts');
    Route::post('/billing/manual-checkouts/{intentId}/approve', [ManualCheckoutReviewController::class, 'adminApproveManualCheckout'])->name('billing.manual_checkouts.approve');
    Route::post('/billing/manual-checkouts/{intentId}/reject', [ManualCheckoutReviewController::class, 'adminRejectManualCheckout'])->name('billing.manual_checkouts.reject');

    // ─── Promo Campaigns CRUD ───
    Route::get('/promos', [PromoCampaignController::class, 'index'])->name('promos.index');
    Route::get('/promos/{id}', [PromoCampaignController::class, 'show'])->name('promos.show');
    Route::post('/promos', [PromoCampaignController::class, 'store'])->name('promos.store');
    Route::put('/promos/{id}', [PromoCampaignController::class, 'update'])->name('promos.update');
    Route::delete('/promos/{id}', [PromoCampaignController::class, 'destroy'])->name('promos.destroy');

    // ─── Admin Invoices ───
    Route::get('/invoices', [InvoiceController::class, 'adminIndex'])->name('invoices.index');

    // ─── Showcase Management ───
    Route::post('/showcase/upload-media', [ShowcaseController::class, 'uploadMedia'])->name('showcase.upload_media');
    Route::get('/showcase/portfolios', [ShowcaseController::class, 'indexPortfolios'])->name('showcase.portfolios.index');
    Route::post('/showcase/portfolios', [ShowcaseController::class, 'storePortfolio'])->name('showcase.portfolios.store');
    Route::put('/showcase/portfolios/{id}', [ShowcaseController::class, 'updatePortfolio'])->name('showcase.portfolios.update');
    Route::delete('/showcase/portfolios/{id}', [ShowcaseController::class, 'destroyPortfolio'])->name('showcase.portfolios.destroy');
    Route::get('/showcase/clients', [ShowcaseController::class, 'indexClients'])->name('showcase.clients.index');
    Route::post('/showcase/clients', [ShowcaseController::class, 'storeClient'])->name('showcase.clients.store');
    Route::put('/showcase/clients/{id}', [ShowcaseController::class, 'updateClient'])->name('showcase.clients.update');
    Route::delete('/showcase/clients/{id}', [ShowcaseController::class, 'destroyClient'])->name('showcase.clients.destroy');
    Route::get('/landing-content', [LandingContentController::class, 'adminContent'])->name('landing_content.index');
    Route::put('/landing-content/about', [LandingContentController::class, 'updateAbout'])->name('landing_content.about.update');
    Route::post('/landing-content/services', [LandingContentController::class, 'storeService'])->name('landing_content.services.store');
    Route::put('/landing-content/services/{id}', [LandingContentController::class, 'updateService'])->name('landing_content.services.update');
    Route::delete('/landing-content/services/{id}', [LandingContentController::class, 'destroyService'])->name('landing_content.services.destroy');
    Route::post('/landing-content/articles/ai-assist', [LandingContentController::class, 'aiAssist'])->middleware('throttle:10,1')->name('landing_content.articles.ai_assist');
    Route::post('/landing-content/articles', [LandingContentController::class, 'storeArticle'])->name('landing_content.articles.store');
    Route::put('/landing-content/articles/{id}', [LandingContentController::class, 'updateArticle'])->name('landing_content.articles.update');
    Route::delete('/landing-content/articles/{id}', [LandingContentController::class, 'destroyArticle'])->name('landing_content.articles.destroy');

    // ─── Hellom Brand Settings ───
    Route::get('/banners', [BannerController::class, 'index']);
    Route::post('/banners', [BannerController::class, 'store']);
    Route::post('/banners/{id}', [BannerController::class, 'update']);
    Route::delete('/banners/{id}', [BannerController::class, 'destroy']);
    Route::get('/brand', [BrandSettingController::class, 'publicShow']);
    Route::put('/brand', [BrandSettingController::class, 'update']);
    Route::post('/brand', [BrandSettingController::class, 'update']);
    Route::get('/mail-settings', [AdminMailController::class, 'showSettings']);
    Route::put('/mail-settings', [AdminMailController::class, 'updateSettings']);
    Route::post('/mail-settings/test', [AdminMailController::class, 'sendTest']);

    Route::get('/notifications', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'unreadCount']);
    Route::get('/notifications/{id}', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'show']);
    Route::patch('/notifications/{id}/read', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'markAsRead']);
    Route::patch('/notifications/read-all', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{id}/execute', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'executeAction']);
    Route::post('/notifications/{id}/ignore', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'ignoreAction']);
    Route::delete('/notifications/{id}', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'destroy']);

    Route::apiResource('digital-products', AdminDigitalProductController::class);
    Route::post('digital-products/{id}/publish', [AdminDigitalProductController::class, 'publish']);
    Route::post('digital-products/{id}/unpublish', [AdminDigitalProductController::class, 'unpublish']);
    Route::post('digital-products/{id}/thumbnail', [AdminDigitalProductController::class, 'uploadThumbnail']);
    Route::post('digital-products/{id}/banner', [AdminDigitalProductController::class, 'uploadBanner']);
    Route::delete('digital-products/{id}/banner', [AdminDigitalProductController::class, 'deleteBanner']);
    Route::post('digital-products/{id}/files', [AdminDigitalProductController::class, 'uploadFile']);
    Route::post('digital-products/{id}/docs', [AdminDigitalProductController::class, 'uploadDoc']);
    Route::delete('digital-products/files/{fileId}', [AdminDigitalProductController::class, 'deleteFile']);
    Route::delete('digital-products/docs/{docId}', [AdminDigitalProductController::class, 'deleteDoc']);
    Route::get('digital-products/docs/{docId}/preview', [AdminDigitalProductController::class, 'previewDoc']);

    Route::get('product-purchases', [ProductPurchaseController::class, 'index']);
    Route::get('product-purchases/{id}', [ProductPurchaseController::class, 'show']);
    Route::post('product-purchases/{id}/approve', [ProductPurchaseController::class, 'approve']);
    Route::post('product-purchases/{id}/refund', [ProductPurchaseController::class, 'refund']);
    // ─── Keuangan penjual (landing page sales, Fase 2) ───
    Route::prefix('seller-finance')->name('seller_finance.')->group(function () {
        Route::get('/summary', [AdminSellerFinanceController::class, 'summary'])->name('summary');
        Route::get('/withdrawals', [AdminSellerFinanceController::class, 'withdrawals'])->name('withdrawals');
        Route::post('/withdrawals/{withdrawalId}/approve', [AdminSellerFinanceController::class, 'approve'])->name('withdrawals.approve');
        Route::post('/withdrawals/{withdrawalId}/mark-paid', [AdminSellerFinanceController::class, 'markPaid'])->name('withdrawals.mark_paid');
        Route::post('/withdrawals/{withdrawalId}/mark-failed', [AdminSellerFinanceController::class, 'markFailed'])->name('withdrawals.mark_failed');
        Route::get('/withdrawals/{withdrawalId}/proof', [AdminSellerFinanceController::class, 'proof'])->name('withdrawals.proof');
        Route::get('/webhooks', [AdminSellerFinanceController::class, 'webhooks'])->name('webhooks');
        Route::get('/reconciliation', [AdminSellerFinanceController::class, 'reconciliation'])->name('reconciliation');
        Route::get('/settings', [AdminSellerFinanceController::class, 'settings'])->name('settings');
        Route::put('/settings', [AdminSellerFinanceController::class, 'updateSettings'])->name('settings.update');
        Route::patch('/sellers/{organizationId}', [AdminSellerFinanceController::class, 'updateSeller'])->name('sellers.update');
        Route::post('/sellers/{organizationId}/adjustment', [AdminSellerFinanceController::class, 'adjustment'])->name('sellers.adjustment');
        Route::get('/export', [AdminSellerFinanceController::class, 'export'])->name('export');
        Route::get('/refunds', [AdminSellerFinanceController::class, 'refunds'])->name('refunds');
        Route::post('/refunds/{refundId}/mark-paid', [AdminSellerFinanceController::class, 'markRefundPaid'])->name('refunds.mark_paid');
        Route::post('/refunds/{refundId}/mark-failed', [AdminSellerFinanceController::class, 'markRefundFailed'])->name('refunds.mark_failed');
        Route::get('/refunds/{refundId}/proof', [AdminSellerFinanceController::class, 'refundProof'])->name('refunds.proof');
    });

    // Moderasi toko Hellom Page (Fase 3): reports, switching sellers/products off.
    Route::prefix('landing-moderation')->name('landing_moderation.')->group(function () {
        Route::get('/reports', [AdminLandingModerationController::class, 'reports'])->name('reports');
        Route::patch('/reports/{reportId}', [AdminLandingModerationController::class, 'updateReport'])->name('reports.update');
        Route::get('/sellers', [AdminLandingModerationController::class, 'sellers'])->name('sellers');
        Route::post('/sellers/{organizationId}/suspend', [AdminLandingModerationController::class, 'suspendSeller'])->name('sellers.suspend');
        Route::get('/products', [AdminLandingModerationController::class, 'products'])->name('products');
        Route::post('/products/{productId}/disable', [AdminLandingModerationController::class, 'disableProduct'])->name('products.disable');
    });
});
