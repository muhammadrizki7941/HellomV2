<?php

namespace App\Services\Realtime;

use App\Models\User;

/**
 * Short-lived tokens that let a browser join its private Socket.IO rooms.
 *
 * Format: base64url(json payload) . "." . base64url(HMAC-SHA256(payload part, REALTIME_SERVER_SECRET)).
 * realtime/server.js verifies the signature with the same shared secret, so
 * it never needs database access.
 */
class RealtimeTokenService
{
    public const TTL_SECONDS = 600;

    /** @return array{token: string, expires_at: int, rooms: array<int, string>}|null null when realtime has no secret */
    public function issue(User $user): ?array
    {
        $secret = (string) config('realtime.secret');
        if ($secret === '') {
            return null;
        }

        $expiresAt = now()->addSeconds(self::TTL_SECONDS)->getTimestamp();
        $rooms = $this->roomsFor($user);

        $payload = $this->base64UrlEncode(json_encode([
            'sub' => (int) $user->id,
            'rooms' => $rooms,
            'exp' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES));

        $signature = $this->base64UrlEncode(hash_hmac('sha256', $payload, $secret, true));

        return [
            'token' => $payload . '.' . $signature,
            'expires_at' => $expiresAt,
            'rooms' => $rooms,
        ];
    }

    /** @return array<int, string> */
    public function roomsFor(User $user): array
    {
        $rooms = ['user_' . (int) $user->id];

        if ((string) $user->role === 'super_admin') {
            $rooms[] = RealtimeClient::ROOM_ADMINS;
        }

        return $rooms;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
