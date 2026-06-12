<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

class BaseApiController extends Controller
{
    protected function success(mixed $data = null, string $message = 'OK', int $status = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'error'   => null,
        ], $status);
    }

    protected function error(string $message, string $code = 'ERROR', mixed $data = null, int $status = 400): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => $data,
            'message' => $message,
            'error'   => ['code' => $code, 'detail' => $message],
        ], $status);
    }
}