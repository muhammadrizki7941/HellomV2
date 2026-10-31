<?php

namespace App\Http\Middleware\Api;

use App\Models\SupportAgent;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupportAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $allowed = $user instanceof User && (
            $user->role === 'super_admin'
            || SupportAgent::query()->where('user_id', $user->id)->where('is_active', true)->exists()
        );

        if (!$allowed) {
            return response()->json([
                'success' => false,
                'message' => 'Akses agent Pusat Bantuan tidak tersedia.',
                'data' => null,
                'error' => ['code' => 'SUPPORT_AGENT_REQUIRED'],
            ], 403);
        }

        return $next($request);
    }
}
