<?php

namespace App\Services\Realtime;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RealtimeClient
{
    /** Room joined only by authenticated super admins (see RealtimeTokenService). */
    public const ROOM_ADMINS = 'admins';

    /** Emit only to sockets in $room (requires realtime/server.js with room support). */
    public function emitToRoom(string $room, string $event, array $data): void
    {
        $this->send(['event' => $event, 'data' => $data, 'room' => $room], $event);
    }

    public function emit(string $event, array $data, ?int $tenantId = null): void
    {
        $this->send(['event' => $event, 'data' => $data, 'tenant_id' => $tenantId], $event);
    }

    private function send(array $body, string $event): void
    {
        if (!config('realtime.enabled')) {
            return;
        }

        $serverUrl = rtrim((string) config('realtime.server_url'), '/');
        $secret = (string) config('realtime.secret');

        if ($serverUrl === '' || $secret === '') {
            // Keep the app working even when realtime is not configured.
            return;
        }

        try {
            Http::timeout((int) config('realtime.timeout_seconds', 1))
                ->withHeaders([
                    'X-RT-SECRET' => $secret,
                ])
                ->post($serverUrl.'/emit', $body);
        } catch (\Throwable $e) {
            // Do not break ordering flow if realtime server is down.
            Log::warning('Realtime emit failed: '.$e->getMessage(), [
                'event' => $event,
                'tenant_id' => $body['tenant_id'] ?? null,
                'room' => $body['room'] ?? null,
            ]);
        }
    }
}
