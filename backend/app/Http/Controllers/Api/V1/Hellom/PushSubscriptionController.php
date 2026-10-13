<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Web push: VAPID public key + save/remove this browser's PushManager subscription. */
class PushSubscriptionController extends BaseApiController
{
    public function publicKey(): JsonResponse
    {
        return $this->ok([
            'enabled' => WebPush::enabled(),
            'public_key' => WebPush::enabled() ? (string) config('services.webpush.public_key') : null,
        ], 'Web push');
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }
        if (!WebPush::enabled()) {
            return $this->fail('Notifikasi push belum diaktifkan di server.', ['code' => 'WEB_PUSH_DISABLED'], 503);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000', 'url:https'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:64'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ]);

        $hash = PushSubscription::hashEndpoint($data['endpoint']);
        // An endpoint belongs to one browser profile: whoever is logged in there now owns it.
        PushSubscription::query()->updateOrCreate(['endpoint_hash' => $hash], [
            'user_id' => $user->id,
            'endpoint' => $data['endpoint'],
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
        ]);

        // Keep it tidy: at most 10 devices per account (oldest go first).
        $keep = PushSubscription::query()->where('user_id', $user->id)->orderByDesc('updated_at')->limit(10)->pluck('id');
        PushSubscription::query()->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();

        return $this->ok(['subscribed' => true], 'Notifikasi push aktif', 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        PushSubscription::query()->where('user_id', $user->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))->delete();

        return $this->ok(['subscribed' => false], 'Notifikasi push dimatikan');
    }
}
