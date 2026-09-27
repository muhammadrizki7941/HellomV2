<?php

/*
| Landing Builder app (requires canUseApp:landing_builder).
| Loaded from routes/api.php inside the v1/hellom prefix group.
*/

use App\Http\Controllers\Api\V1\Hellom\EntitlementController;
use App\Http\Controllers\Api\V1\Hellom\FileAssetController;
use App\Http\Controllers\Api\V1\Hellom\LandingBuilderController;
use Illuminate\Support\Facades\Route;

Route::get('/apps/landing-builder/probe', [EntitlementController::class, 'probeAllowed'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.probe');
Route::get('/apps/landing-builder/pages', [LandingBuilderController::class, 'index'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.index');
Route::get('/apps/landing-builder/customers', [LandingBuilderController::class, 'customers'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.customers.index');
Route::post('/apps/landing-builder/pages', [LandingBuilderController::class, 'store'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.store');
Route::get('/apps/landing-builder/pages/{id}', [LandingBuilderController::class, 'show'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.show');
Route::put('/apps/landing-builder/pages/{id}', [LandingBuilderController::class, 'update'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.update');
Route::delete('/apps/landing-builder/pages/{id}', [LandingBuilderController::class, 'destroy'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.destroy');
Route::post('/apps/landing-builder/pages/{id}/duplicate', [LandingBuilderController::class, 'duplicate'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.duplicate');
Route::get('/apps/landing-builder/templates', [LandingBuilderController::class, 'templates'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.templates.index');
Route::get('/apps/landing-builder/templates/{key}', [LandingBuilderController::class, 'templateDetail'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.templates.show');
Route::get('/apps/landing-builder/templates/{key}/block-keys', [LandingBuilderController::class, 'templateBlockKeys'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.templates.block_keys');
Route::post('/apps/landing-builder/pages/{id}/apply-template', [LandingBuilderController::class, 'applyTemplate'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.apply_template');
Route::get('/apps/landing-builder/pages/{id}/blocks', [LandingBuilderController::class, 'blocks'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.blocks.index');
Route::post('/apps/landing-builder/pages/{id}/blocks', [LandingBuilderController::class, 'storeBlock'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.blocks.store');
Route::post('/apps/landing-builder/pages/{id}/blocks/reorder', [LandingBuilderController::class, 'reorderBlocks'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.blocks.reorder');
Route::put('/apps/landing-builder/pages/{id}/blocks/{blockId}', [LandingBuilderController::class, 'updateBlock'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.blocks.update');
Route::delete('/apps/landing-builder/pages/{id}/blocks/{blockId}', [LandingBuilderController::class, 'destroyBlock'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.blocks.destroy');
Route::post('/apps/landing-builder/pages/{id}/publish', [LandingBuilderController::class, 'publish'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.publish');
Route::post('/apps/landing-builder/pages/{id}/unpublish', [LandingBuilderController::class, 'unpublish'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.unpublish');
Route::get('/apps/landing-builder/pages/{id}/versions', [LandingBuilderController::class, 'versions'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.versions');
Route::post('/apps/landing-builder/pages/{id}/versions/{versionId}/restore', [LandingBuilderController::class, 'restoreVersion'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.versions.restore');
Route::get('/apps/landing-builder/pages/{id}/domains', [LandingBuilderController::class, 'domains'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.domains.index');
Route::post('/apps/landing-builder/pages/{id}/domains', [LandingBuilderController::class, 'storeDomain'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.domains.store');
Route::put('/apps/landing-builder/pages/{id}/domains/{domainId}', [LandingBuilderController::class, 'updateDomain'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.domains.update');
Route::delete('/apps/landing-builder/pages/{id}/domains/{domainId}', [LandingBuilderController::class, 'destroyDomain'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.pages.domains.destroy');
Route::get('/apps/landing-builder/stats', [LandingBuilderController::class, 'stats'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.stats');
Route::get('/apps/landing-builder/stats/pages', [LandingBuilderController::class, 'pageStats'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.stats.pages');
Route::get('/apps/landing-builder/stats/funnel', [LandingBuilderController::class, 'funnelKpi'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.stats.funnel');
Route::get('/apps/landing-builder/stats/performance', [LandingBuilderController::class, 'performanceSummary'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.stats.performance');
Route::get('/apps/landing-builder/assets', [FileAssetController::class, 'index'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.assets.index');
Route::post('/apps/landing-builder/assets/upload', [FileAssetController::class, 'upload'])
    ->middleware('canUseApp:landing_builder')
    ->name('apps.landing_builder.assets.upload');
