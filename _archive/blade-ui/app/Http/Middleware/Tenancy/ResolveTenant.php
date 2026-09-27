<?php

namespace App\Http\Middleware\Tenancy;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->attributes->has('tenant')) {
            $slug = (string) ($request->route('tenant') ?? '');
            $all = (array) config('tenancy.tenants', []);

            if ($slug !== '' && isset($all[$slug]) && is_array($all[$slug])) {
                $row = $all[$slug];
                $request->attributes->set('tenant', new TenantContext(
                    id: null,
                    slug: $slug,
                    name: (string) ($row['name'] ?? strtoupper($slug)),
                    plan: (string) ($row['plan'] ?? 'trial'),
                    status: (string) ($row['status'] ?? 'active'),
                    trialStartedAt: isset($row['trial_started_at']) ? (string) $row['trial_started_at'] : null,
                    activeUntil: isset($row['active_until']) ? (string) $row['active_until'] : null,
                    subdomain: isset($row['subdomain']) ? (string) $row['subdomain'] : null,
                    customDomain: isset($row['custom_domain']) ? (string) $row['custom_domain'] : null,
                ));
            }
        }

        return $next($request);
    }
}
