<?php

namespace App\Http\Middleware\Api;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $bearer = (string) $request->bearerToken();
        if ($bearer === '') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
                'data' => null,
                'error' => ['code' => 'UNAUTHORIZED', 'detail' => 'Missing bearer token'],
            ], 401);
        }

        $tokenHash = hash('sha256', $bearer);

        $apiToken = ApiToken::query()
            ->with('user')
            ->where('token_hash', $tokenHash)
            ->first();

        if (!$apiToken || !$apiToken->user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
                'data' => null,
                'error' => ['code' => 'UNAUTHORIZED', 'detail' => 'Invalid token'],
            ], 401);
        }

        if ($apiToken->expires_at !== null && $apiToken->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
                'data' => null,
                'error' => ['code' => 'UNAUTHORIZED', 'detail' => 'Token expired'],
            ], 401);
        }

        $apiToken->forceFill(['last_used_at' => Carbon::now()])->save();

        $request->attributes->set('apiToken', $apiToken);
        $request->setUserResolver(fn() => $apiToken->user);

        return $next($request);
    }
}
