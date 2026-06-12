<?php

namespace App\Services\Realtime;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RealtimeClient
{
    public function emit(string $event, array $data, ?int $tenantId = null): void
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
                ->post($serverUrl.'/emit', [
                    'event' => $event,
                    'data' => $data,
                    'tenant_id' => $tenantId,
                ]);
        } catch (\Throwable $e) {
            // Do not break ordering flow if realtime server is down.
            Log::warning('Realtime emit failed: '.$e->getMessage(), ['event' => $event, 'tenant_id' => $tenantId]);
        }
    }
}
