<?php

namespace App\Http\Middleware\Tenancy;

use Closure;
use Illuminate\Http\Request;

class EnsureTenantHost
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}
