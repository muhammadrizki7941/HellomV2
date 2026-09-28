<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Pos\PricingException;
use Illuminate\Http\JsonResponse;

/**
 * Base for every API v1 controller. All responses use the same envelope:
 * { success, message, data, error }.
 *
 * Two helper pairs exist for historical reasons and produce the same envelope:
 * - ok() / fail(): Hellom platform controllers (error = free-form array)
 * - success() / error(): POS and consumer controllers (error = {code, detail})
 * Prefer ok()/fail() in new code.
 */
class BaseApiController extends Controller
{
    protected function ok(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'error' => null,
        ], $status);
    }

    protected function fail(string $message, mixed $error = null, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'error' => $error,
        ], $status);
    }

    protected function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'error'   => null,
        ], $status);
    }

    protected function error(string $message, string $code = 'ERROR', mixed $data = null, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => $data,
            'message' => $message,
            'error'   => ['code' => $code, 'detail' => $message],
        ], $status);
    }

    /** Order/pricing rule violation → { error: { code, detail, problems } } with its HTTP status. */
    protected function orderRuleFailed(PricingException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $e->getMessage(),
            'error' => ['code' => $e->errorCode, 'detail' => $e->getMessage(), 'problems' => $e->problems],
        ], $e->status);
    }
}
