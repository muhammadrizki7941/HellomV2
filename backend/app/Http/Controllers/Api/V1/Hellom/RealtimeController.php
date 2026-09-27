<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\User;
use App\Services\Realtime\RealtimeTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeController extends BaseApiController
{
    /** GET /realtime/token — short-lived token for the Socket.IO handshake. */
    public function token(Request $request, RealtimeTokenService $tokens): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $issued = $tokens->issue($user);
        if ($issued === null) {
            return $this->fail('Realtime is not configured', ['code' => 'REALTIME_NOT_CONFIGURED'], 503);
        }

        return $this->ok($issued, 'Realtime token');
    }
}
