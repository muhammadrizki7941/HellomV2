<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\TrustProxies::class);
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        $middleware->alias([
            'set.locale' => \App\Http\Middleware\SetLocale::class,
            'canUseApp' => \App\Http\Middleware\Api\EnsureAppEntitlement::class,
            'superAdmin' => \App\Http\Middleware\Api\EnsureSuperAdmin::class,
            'injectPosContext' => \App\Http\Middleware\Api\InjectPosContext::class,
            'billing.mock' => \App\Http\Middleware\Api\EnsureBillingMockEnabled::class,
            'logPaymentWebhook' => \App\Http\Middleware\Api\LogPaymentWebhook::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
