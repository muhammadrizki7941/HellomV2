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
    public function issue(User $user, array $extraRooms = []): ?array
    {
        return $this->issueFor((int) $user->id, array_values(array_unique([...$this->roomsFor($user), ...$extraRooms])));
    }

    /**
     * Token for an explicit room list. Callers must have checked access to every room:
     * POS outlet rooms (tenant:{slug}:outlet:{id}) via InjectPosContext, guest table
     * rooms (table:{id}) via a valid QR token. $subject is 0 for guests.
     *
     * @param array<int, string> $rooms
     * @return array{token: string, expires_at: int, rooms: array<int, string>}|null
     */
    public function issueFor(int $subject, array $rooms): ?array
    {
        $secret = (string) config('realtime.secret');
        if ($secret === '') {
            return null;
        }

        $expiresAt = now()->addSeconds(self::TTL_SECONDS)->getTimestamp();

        $payload = $this->base64UrlEncode(json_encode([
            'sub' => $subject,
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
