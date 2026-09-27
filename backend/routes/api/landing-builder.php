<?php

/*
| Landing Builder app (requires canUseApp:landing_builder).
| Loaded from routes/api.php inside the v1/hellom prefix group.
*/

use App\Http\Controllers\Api\V1\Hellom\EntitlementController;
use App\Http\Controllers\Api\V1\Hellom\FileAssetController;
use App\Http\Controllers\Api\V1\Hellom\LandingBuilderController;
use Illuminate\Support\Facades\Route;

Route::middleware('canUseApp:landing_builder')->group(function () {
    Route::get('/apps/landing-builder/probe', [EntitlementController::class, 'probeAllowed'])
        ->name('apps.landing_builder.probe');
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
