<?php

namespace App\Http\Middleware\Dev;

use Closure;
use Illuminate\Http\Request;

class EnsureLocalOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (!app()->environment('local')) {
            abort(404);
        }

        return $next($request);
    }
}
