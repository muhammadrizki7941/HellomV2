<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Services\Pos\Audit\OwnerPinException;
use App\Services\Pos\Audit\OwnerPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public page /reset-pin-pos?token=… from the email sent to the shop's primary owner. */
class PosPinResetController extends BaseApiController
{
    public function __construct(private readonly OwnerPinService $pins)
    {
    }

    public function show(string $token): JsonResponse
    {
        try {
            return $this->success($this->pins->resetInfo($token), 'Link reset PIN');
        } catch (OwnerPinException $e) {
            return $this->error($e->getMessage(), $e->errorCode, null, $e->status);
        }
    }

    public function reset(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate(['pin' => 'required|string|confirmed'], [
            'pin.required' => 'Masukkan PIN 6 angka.',
            'pin.confirmed' => 'Konfirmasi PIN belum sama.',
        ]);
        try {
            $org = $this->pins->resetWithToken($token, (string) $validated['pin']);
        } catch (OwnerPinException $e) {
            $body = ['success' => false, 'message' => $e->getMessage(), 'data' => null, 'error' => ['code' => $e->errorCode, 'detail' => $e->getMessage()]];
            if (in_array($e->errorCode, ['PIN_FORMAT', 'PIN_WEAK'], true)) {
                $body['errors'] = ['pin' => [$e->getMessage()]];
            }

            return response()->json($body, $e->status);
        }

        return $this->success(['organization' => $org->name], 'PIN baru tersimpan');
    }
}
