<?php

namespace App\Listeners;

use App\Services\Cache\TenantCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class ClearTenantCacheOnModelChange
{
    /**
     * Create the event listener.
     */
    public function __construct(
        private TenantCache $cache
    ) {}

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        // Clear notification counts cache when orders or reservations change
        if (method_exists($event, 'order') || method_exists($event, 'reservation')) {
            $this->cache->forget('notification_counts');
        }

        // Clear specific caches based on model type
        $model = $event->order ?? $event->reservation ?? $event->product ?? $event->category ?? null;
        if ($model) {
            $this->clearModelSpecificCache($model);
        }
    }

    /**
     * Clear model-specific cache
     */
    private function clearModelSpecificCache($model): void
    {
        $modelClass = get_class($model);

        switch ($modelClass) {
            case 'App\Models\Order':
                $this->cache->forget('notification_counts');
                break;
            case 'App\Models\Reservation':
                $this->cache->forget('notification_counts');
                break;
            case 'App\Models\Product':
                $this->cache->forget('products');
                $this->cache->forget('categories'); // Also clear categories cache since products are included
                break;
            case 'App\Models\Category':
                $this->cache->forget('categories');
                break;
            case 'App\Models\DiningTable':
                $this->cache->forget('dining_tables');
                break;
        }
    }
}