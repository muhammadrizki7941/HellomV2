<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Cache\TenantCache;
use App\Services\Tenancy\TenantContext;

class CacheServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantCache::class, function ($app) {
            $tenant = $app->bound(TenantContext::class) ? $app->make(TenantContext::class) : null;
            return new TenantCache($tenant);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}