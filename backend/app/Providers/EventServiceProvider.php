<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Listeners\ClearTenantCacheOnModelChange;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        'eloquent.created: App\Models\Order' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.updated: App\Models\Order' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.deleted: App\Models\Order' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.created: App\Models\Reservation' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.updated: App\Models\Reservation' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.deleted: App\Models\Reservation' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.created: App\Models\Product' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.updated: App\Models\Product' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.deleted: App\Models\Product' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.created: App\Models\Category' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.updated: App\Models\Category' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.deleted: App\Models\Category' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.created: App\Models\DiningTable' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.updated: App\Models\DiningTable' => [
            ClearTenantCacheOnModelChange::class,
        ],
        'eloquent.deleted: App\Models\DiningTable' => [
            ClearTenantCacheOnModelChange::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}