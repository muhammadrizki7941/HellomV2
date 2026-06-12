<?php

namespace App\Http\Middleware\Api;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof User || (string) $user->role !== 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: super admin access required',
                'data' => null,
                'error' => ['code' => 'FORBIDDEN_SUPER_ADMIN'],
            ], 403);
        }

        return $next($request);
    }
}
