<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mock billing endpoints (fake top-up, fake checkout confirm, fake renew)
 * credit balances and activate entitlements without any payment. They exist
 * for local development only and answer 404 unless BILLING_MOCK_ENABLED=true.
 */
class EnsureBillingMockEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('payments.mock.enabled')) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
                'data' => null,
                'error' => ['code' => 'NOT_FOUND'],
            ], 404);
        }

        return $next($request);
    }
}
