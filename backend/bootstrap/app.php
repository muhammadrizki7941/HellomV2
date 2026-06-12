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
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'set.locale' => \App\Http\Middleware\SetLocale::class,
            // Tenancy (foundation: no DB)
            'tenant.resolve' => \App\Http\Middleware\Tenancy\ResolveTenant::class,
            'tenant.active' => \App\Http\Middleware\Tenancy\EnsureTenantActive::class,
            'tenant.host' => \App\Http\Middleware\Tenancy\EnsureTenantHost::class,

            // Dummy auth (foundation: no DB)
            'auth.global' => \App\Http\Middleware\AuthZ\EnsureGlobalAuthenticated::class,
            'role.super' => \App\Http\Middleware\AuthZ\EnsureRoleIsSuperAdmin::class,
            'role.tenant_admin' => \App\Http\Middleware\AuthZ\EnsureRoleIsTenantAdmin::class,
            'role.cashier' => \App\Http\Middleware\AuthZ\EnsureRoleIsCashier::class,
            'role.tenant_admin_or_cashier' => \App\Http\Middleware\AuthZ\EnsureRoleIsTenantAdminOrCashier::class,
            'canUseApp' => \App\Http\Middleware\Api\EnsureAppEntitlement::class,
            'superAdmin' => \App\Http\Middleware\Api\EnsureSuperAdmin::class,
            'injectPosContext' => \App\Http\Middleware\Api\InjectPosContext::class,
                'web.pos.entitled' => \App\Http\Middleware\EnsureWebPosEntitlement::class,

            // Dev helpers
            'local.only' => \App\Http\Middleware\Dev\EnsureLocalOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
