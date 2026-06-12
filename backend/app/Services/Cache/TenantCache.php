<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;
use App\Services\Tenancy\TenantContext;

class TenantCache
{
    public function __construct(
        private ?TenantContext $tenant = null
    ) {}

    /**
     * Get tenant-specific cache key
     * Gets current tenant from container each time to handle late binding
     * Uses slug as primary identifier, falls back to id, then global
     */
    private function getTenantKey(string $key): string
    {
        // Get current tenant from container each time
        $tenant = app()->bound(TenantContext::class) ? app()->make(TenantContext::class) : null;
        // Use slug as primary identifier (since DB tenant_id now stores slug)
        $tenantIdentifier = $tenant?->slug ?? ($tenant?->id ?? 'global');
        return "tenant_{$tenantIdentifier}:{$key}";
    }

    /**
     * Get cache value for current tenant
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::get($this->getTenantKey($key), $default);
    }

    /**
     * Put cache value for current tenant
     */
    public function put(string $key, mixed $value, int $ttl = null): bool
    {
        return Cache::put($this->getTenantKey($key), $value, $ttl);
    }

    /**
     * Remember cache value for current tenant
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        return Cache::remember($this->getTenantKey($key), $ttl, $callback);
    }

    /**
     * Remember forever for current tenant
     */
    public function rememberForever(string $key, callable $callback): mixed
    {
        return Cache::rememberForever($this->getTenantKey($key), $callback);
    }

    /**
     * Check if cache key exists for current tenant
     */
    public function has(string $key): bool
    {
        return Cache::has($this->getTenantKey($key));
    }

    /**
     * Delete cache key for current tenant
     */
    public function forget(string $key): bool
    {
        return Cache::forget($this->getTenantKey($key));
    }

    /**
     * Clear all cache for current tenant
     */
    public function clearTenantCache(): void
    {
        // This is a simplified implementation
        // In production, you might want to use Redis SCAN or similar
        // For now, we'll just clear common tenant-specific keys
        // Get current tenant from container
        $tenant = app()->bound(TenantContext::class) ? app()->make(TenantContext::class) : null;
        $tenantIdentifier = $tenant?->slug ?? ($tenant?->id ?? 'global');
        $prefix = "tenant_{$tenantIdentifier}:";

        // Get all cache keys (this is database-specific)
        // For Redis, you could use SCAN command
        // For simplicity, we'll clear known keys
        $knownKeys = [
            'orders_new_count',
            'reservations_pending_count',
            'brand_settings',
            'payment_settings',
            'loyalty_settings',
            'categories',
            'products',
            'dining_tables',
        ];

        foreach ($knownKeys as $key) {
            Cache::forget($prefix . $key);
        }
    }

    /**
     * Get or set tenant context
     * Kept for backward compatibility
     */
    public function setTenant(?TenantContext $tenant): self
    {
        $this->tenant = $tenant;
        return $this;
    }
}