<?php

namespace App\Services\Notifications;

use App\Models\ConsumerNotification;
use App\Models\OwnerNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * One way to tell people something happened (in-app bell, realtime, web push; email stays with the
 * feature that already sends it):
 *
 *   admin()        super admin inbox (owner_notifications) — realtime "admins" + web push + owner email copy
 *   organization() every owner/admin of a shop (consumer_notifications per user) — e.g. "Penjualan baru"
 *   user()         one account
 *   resolveAdmin() close the super admin item once the work is done elsewhere (e.g. withdrawal paid)
 *
 * Realtime/web push for consumer notifications happen in ConsumerNotification::booted (after commit),
 * so older callers of NotificationService get them too. Never throws: a notification must not break
 * the money flow that triggered it.
 */
final class Notifier
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * @param array{data?: array, action_url?: ?string, action_type?: ?string, action_status?: ?string,
     *              reference_type?: ?string, reference_id?: ?int, email?: bool} $options
     */
    public function admin(string $type, string $title, string $message, array $options = []): ?OwnerNotification
    {
        try {
            $url = $options['action_url'] ?? null;
            $notification = OwnerNotification::query()->create([
                'type' => $type,
                'title' => mb_substr($title, 0, 255),
                'message' => $message,
                'data' => $options['data'] ?? null,
                'is_read' => false,
                'action_type' => $options['action_type'] ?? ($url ? 'open_page' : null),
                'action_url' => $url,
                'action_status' => $options['action_status'] ?? null,
                'reference_type' => $options['reference_type'] ?? null,
                'reference_id' => $options['reference_id'] ?? null,
            ]);
            $this->notifications->emitCreated($notification, (bool) ($options['email'] ?? true));

            return $notification;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Mark open super admin items about this record as done (the action happened elsewhere). */
    public function resolveAdmin(string $referenceType, int $referenceId, string $status = 'done'): void
    {
        try {
            OwnerNotification::query()->where('reference_type', $referenceType)->where('reference_id', $referenceId)
                ->where('action_status', 'pending')->update(['action_status' => $status, 'action_done_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Every owner/admin member of the organization gets the notification in their dashboard bell. */
    public function organization(int $organizationId, string $type, string $title, string $body, ?string $actionUrl = null, array $data = []): int
    {
        try {
            $userIds = DB::table('organization_user')->where('organization_id', $organizationId)->whereIn('role', ['owner', 'admin'])->pluck('user_id');
            foreach ($userIds as $userId) {
                $this->create((int) $userId, $type, $title, $body, $actionUrl, $data + ['organization_id' => $organizationId]);
            }

            return $userIds->count();
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    public function user(User|int $user, string $type, string $title, string $body, ?string $actionUrl = null, array $data = []): ?ConsumerNotification
    {
        try {
            return $this->create($user instanceof User ? (int) $user->id : $user, $type, $title, $body, $actionUrl, $data);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function rupiah(int $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }

    private function create(int $userId, string $type, string $title, string $body, ?string $actionUrl, array $data): ConsumerNotification
    {
        return ConsumerNotification::query()->create([
            'user_id' => $userId,
            'type' => mb_substr($type, 0, 50),
            'title' => mb_substr($title, 0, 255),
            'body' => $body,
            'data' => $data ?: null,
            'is_read' => false,
            'action_type' => $actionUrl ? 'open_page' : null,
            'action_url' => $actionUrl,
        ]);
    }
}
