<?php

/*
| Landing Builder app (requires canUseApp:landing_builder), plus the seller order
| endpoints of Hellom Page, which stay open after a subscription ends.
| Loaded from routes/api.php inside the v1/hellom prefix group.
*/

use App\Http\Controllers\Api\V1\Hellom\EmailVerificationController;
use App\Http\Controllers\Api\V1\Hellom\EntitlementController;
use App\Http\Controllers\Api\V1\Hellom\FileAssetController;
use App\Http\Controllers\Api\V1\Hellom\LandingBuilderController;
use App\Http\Controllers\Api\V1\Hellom\LandingSiteController;
use App\Http\Controllers\Api\V1\Hellom\SellerMarketingController;
use App\Http\Controllers\Api\V1\Hellom\SellerCouponController;
use App\Http\Controllers\Api\V1\Hellom\SellerOrderController;
use App\Http\Controllers\Api\V1\Hellom\SellerProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('canUseApp:landing_builder')->group(function () {
    Route::get('/apps/landing-builder/probe', [EntitlementController::class, 'probeAllowed'])
        ->name('apps.landing_builder.probe');

    // Old editor API, leads, domains and files (LB-24): owners/admins only, not POS staff.
    Route::middleware('shopManager')->group(function () {
        Route::get('/apps/landing-builder/pages', [LandingBuilderController::class, 'index'])
            ->name('apps.landing_builder.pages.index');
        Route::get('/apps/landing-builder/customers', [LandingBuilderController::class, 'customers'])
            ->name('apps.landing_builder.customers.index');
        Route::post('/apps/landing-builder/pages', [LandingBuilderController::class, 'store'])
            ->name('apps.landing_builder.pages.store');
        Route::get('/apps/landing-builder/pages/{id}', [LandingBuilderController::class, 'show'])
            ->name('apps.landing_builder.pages.show');
        Route::put('/apps/landing-builder/pages/{id}', [LandingBuilderController::class, 'update'])
            ->name('apps.landing_builder.pages.update');
        Route::delete('/apps/landing-builder/pages/{id}', [LandingBuilderController::class, 'destroy'])
            ->name('apps.landing_builder.pages.destroy');
        Route::post('/apps/landing-builder/pages/{id}/duplicate', [LandingBuilderController::class, 'duplicate'])
            ->name('apps.landing_builder.pages.duplicate');
        Route::get('/apps/landing-builder/templates', [LandingBuilderController::class, 'templates'])
            ->name('apps.landing_builder.templates.index');
        Route::get('/apps/landing-builder/templates/{key}', [LandingBuilderController::class, 'templateDetail'])
            ->name('apps.landing_builder.templates.show');
        Route::get('/apps/landing-builder/templates/{key}/block-keys', [LandingBuilderController::class, 'templateBlockKeys'])
            ->name('apps.landing_builder.templates.block_keys');
        Route::post('/apps/landing-builder/pages/{id}/apply-template', [LandingBuilderController::class, 'applyTemplate'])
            ->name('apps.landing_builder.pages.apply_template');
        Route::get('/apps/landing-builder/pages/{id}/blocks', [LandingBuilderController::class, 'blocks'])
            ->name('apps.landing_builder.pages.blocks.index');
        Route::post('/apps/landing-builder/pages/{id}/blocks', [LandingBuilderController::class, 'storeBlock'])
            ->name('apps.landing_builder.pages.blocks.store');
        Route::post('/apps/landing-builder/pages/{id}/blocks/reorder', [LandingBuilderController::class, 'reorderBlocks'])
            ->name('apps.landing_builder.pages.blocks.reorder');
        Route::put('/apps/landing-builder/pages/{id}/blocks/{blockId}', [LandingBuilderController::class, 'updateBlock'])
            ->name('apps.landing_builder.pages.blocks.update');
        Route::delete('/apps/landing-builder/pages/{id}/blocks/{blockId}', [LandingBuilderController::class, 'destroyBlock'])
            ->name('apps.landing_builder.pages.blocks.destroy');
        Route::post('/apps/landing-builder/pages/{id}/publish', [LandingBuilderController::class, 'publish'])
            ->name('apps.landing_builder.pages.publish');
        Route::post('/apps/landing-builder/pages/{id}/unpublish', [LandingBuilderController::class, 'unpublish'])
            ->name('apps.landing_builder.pages.unpublish');
        Route::get('/apps/landing-builder/pages/{id}/versions', [LandingBuilderController::class, 'versions'])
            ->name('apps.landing_builder.pages.versions');
        Route::post('/apps/landing-builder/pages/{id}/versions/{versionId}/restore', [LandingBuilderController::class, 'restoreVersion'])
            ->name('apps.landing_builder.pages.versions.restore');
        Route::get('/apps/landing-builder/pages/{id}/domains', [LandingBuilderController::class, 'domains'])
            ->name('apps.landing_builder.pages.domains.index');
        Route::post('/apps/landing-builder/pages/{id}/domains', [LandingBuilderController::class, 'storeDomain'])
            ->name('apps.landing_builder.pages.domains.store');
        Route::put('/apps/landing-builder/pages/{id}/domains/{domainId}', [LandingBuilderController::class, 'updateDomain'])
            ->name('apps.landing_builder.pages.domains.update');
        Route::delete('/apps/landing-builder/pages/{id}/domains/{domainId}', [LandingBuilderController::class, 'destroyDomain'])
            ->name('apps.landing_builder.pages.domains.destroy');
        Route::get('/apps/landing-builder/stats', [LandingBuilderController::class, 'stats'])
            ->name('apps.landing_builder.stats');
        Route::get('/apps/landing-builder/stats/pages', [LandingBuilderController::class, 'pageStats'])
            ->name('apps.landing_builder.stats.pages');
        Route::get('/apps/landing-builder/stats/funnel', [LandingBuilderController::class, 'funnelKpi'])
            ->name('apps.landing_builder.stats.funnel');
        Route::get('/apps/landing-builder/stats/performance', [LandingBuilderController::class, 'performanceSummary'])
            ->name('apps.landing_builder.stats.performance');
        Route::get('/apps/landing-builder/assets', [FileAssetController::class, 'index'])
            ->name('apps.landing_builder.assets.index');
        Route::post('/apps/landing-builder/assets/upload', [FileAssetController::class, 'upload'])
            ->name('apps.landing_builder.assets.upload');
    });

    // Products & coupons (Fase 3) — owner/admin of the shop.
    Route::prefix('/apps/landing-builder')->name('apps.landing_builder.')->group(function () {
        Route::get('/products', [SellerProductController::class, 'index'])->name('products.index');
        Route::post('/products', [SellerProductController::class, 'store'])->name('products.store');
        Route::post('/products/check-drive-link', [SellerProductController::class, 'checkDriveLink'])->name('products.check_drive_link');
        Route::get('/products/{productId}', [SellerProductController::class, 'show'])->whereNumber('productId')->name('products.show');
        Route::put('/products/{productId}', [SellerProductController::class, 'update'])->whereNumber('productId')->name('products.update');
        Route::post('/products/{productId}/toggle', [SellerProductController::class, 'toggle'])->whereNumber('productId')->name('products.toggle');
        Route::delete('/products/{productId}', [SellerProductController::class, 'destroy'])->whereNumber('productId')->name('products.destroy');
        Route::post('/products/{productId}/image', [SellerProductController::class, 'uploadImage'])->whereNumber('productId')->name('products.image');
        Route::post('/products/{productId}/file', [SellerProductController::class, 'uploadFile'])->whereNumber('productId')->name('products.file');
        Route::delete('/products/{productId}/file', [SellerProductController::class, 'deleteFile'])->whereNumber('productId')->name('products.file.destroy');
        Route::get('/coupons', [SellerCouponController::class, 'index'])->name('coupons.index');
        Route::post('/coupons', [SellerCouponController::class, 'store'])->name('coupons.store');
        Route::put('/coupons/{couponId}', [SellerCouponController::class, 'update'])->whereNumber('couponId')->name('coupons.update');
        Route::delete('/coupons/{couponId}', [SellerCouponController::class, 'destroy'])->whereNumber('couponId')->name('coupons.destroy');

        // Editor (Fase 4): shop username, pages, draft autosave, publish, history, preview.
        Route::get('/site', [LandingSiteController::class, 'show'])->name('site.show');
        Route::get('/onboarding', [LandingSiteController::class, 'onboarding'])->name('onboarding');
        Route::put('/site/username', [LandingSiteController::class, 'updateUsername'])->middleware('throttle:10,1')->name('site.username');
        Route::post('/site/pages', [LandingSiteController::class, 'createPage'])->name('site.pages.store');
        Route::patch('/site/pages/{pageId}', [LandingSiteController::class, 'updatePage'])->whereNumber('pageId')->name('site.pages.update');
        Route::delete('/site/pages/{pageId}', [LandingSiteController::class, 'deletePage'])->whereNumber('pageId')->name('site.pages.destroy');
        Route::get('/site/pages/{pageId}/document', [LandingSiteController::class, 'document'])->whereNumber('pageId')->name('site.pages.document');
        Route::put('/site/pages/{pageId}/document', [LandingSiteController::class, 'saveDocument'])->whereNumber('pageId')->name('site.pages.document.save');
        Route::post('/site/pages/{pageId}/publish', [LandingSiteController::class, 'publish'])->whereNumber('pageId')->name('site.pages.publish');
        Route::post('/site/pages/{pageId}/unpublish', [LandingSiteController::class, 'unpublish'])->whereNumber('pageId')->name('site.pages.unpublish');
        Route::get('/site/pages/{pageId}/history', [LandingSiteController::class, 'history'])->whereNumber('pageId')->name('site.pages.history');
        Route::post('/site/pages/{pageId}/history/{versionId}/restore', [LandingSiteController::class, 'restore'])->whereNumber('pageId')->whereNumber('versionId')->name('site.pages.restore');
        Route::post('/site/pages/{pageId}/preview-link', [LandingSiteController::class, 'previewLink'])->whereNumber('pageId')->name('site.pages.preview');
        // Ads & stats.
        Route::get('/tracking', [SellerMarketingController::class, 'tracking'])->name('tracking.show');
        Route::put('/tracking', [SellerMarketingController::class, 'updateTracking'])->name('tracking.update');
        Route::get('/stats/traffic', [SellerMarketingController::class, 'stats'])->name('stats.traffic');
    });
});

// Orders, buyers (Fase 3): not behind the subscription — a seller must be able to fulfil
// and refund what was sold, like the sales balance.
Route::prefix('/seller/orders')->name('seller_orders.')->group(function () {
    Route::get('/summary', [SellerOrderController::class, 'summary'])->name('summary');
    Route::get('/', [SellerOrderController::class, 'index'])->name('index');
    Route::get('/export', [SellerOrderController::class, 'exportOrders'])->name('export');
    Route::get('/buyers', [SellerOrderController::class, 'buyers'])->name('buyers');
    Route::get('/buyers/export', [SellerOrderController::class, 'exportBuyers'])->name('buyers.export');
    Route::get('/{orderId}', [SellerOrderController::class, 'show'])->whereNumber('orderId')->name('show');
    Route::post('/{orderId}/resend', [SellerOrderController::class, 'resend'])->whereNumber('orderId')->middleware('throttle:hellom-landing-mail')->name('resend');
    Route::post('/{orderId}/fulfill', [SellerOrderController::class, 'fulfill'])->whereNumber('orderId')->name('fulfill');
    Route::post('/{orderId}/refund', [SellerOrderController::class, 'refund'])->whereNumber('orderId')->middleware('throttle:10,1')->name('refund');
});
Route::post('/account/email/verification', [EmailVerificationController::class, 'send'])->middleware('throttle:hellom-landing-mail')
    ->name('account.email.verification');
