<?php

namespace App\Http\Middleware\Tenancy;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class EnsureTenantActive
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = $request->attributes->get('tenant');
        if ($tenant instanceof TenantContext && strtolower($tenant->status) !== 'active') {
            abort(403, 'Tenant is not active.');
        }

        return $next($request);
    }
}
