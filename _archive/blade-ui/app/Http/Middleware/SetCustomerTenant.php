<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class SetCustomerTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (!$request->attributes->has('tenant')) {
            $all = (array) config('tenancy.tenants', []);
            if (!empty($all)) {
                // Take the first tenant
                $first = array_values($all)[0];
                $tenantContext = new TenantContext(
                    id: $first['id'] ?? null,
                    slug: $first['slug'] ?? '',
                    name: $first['name'] ?? '',
                    plan: $first['plan'] ?? 'trial',
                    status: $first['status'] ?? 'active',
                    trialStartedAt: isset($first['trial_started_at']) ? (string) $first['trial_started_at'] : null,
                    activeUntil: isset($first['active_until']) ? (string) $first['active_until'] : null,
                    subdomain: isset($first['subdomain']) ? (string) $first['subdomain'] : null,
                    customDomain: isset($first['custom_domain']) ? (string) $first['custom_domain'] : null,
                );
                
                $request->attributes->set('tenant', $tenantContext);

                // Also bind in container for service providers
                if (isset($request->app) && !$request->app->bound(TenantContext::class)) {
                    $request->app->instance(TenantContext::class, $tenantContext);
                }
            }
        }

        return $next($request);
    }
}