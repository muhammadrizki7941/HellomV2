<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Models\ConsumerNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $notifications = ConsumerNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $unreadCount = ConsumerNotification::query()
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        return $this->ok([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $count = ConsumerNotification::query()
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        return $this->ok(['count' => $count]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $notification = ConsumerNotification::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $notification->forceFill([
            'is_read' => true,
            'read_at' => now(),
        ])->save();

        return $this->ok(true);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        ConsumerNotification::query()
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return $this->ok(true);
    }
}
