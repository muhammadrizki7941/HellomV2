<?php

namespace App\Services\Notifications;

use App\Jobs\SendWebPush;
use App\Models\User;

/**
 * Browser/phone push for people who are not looking at the dashboard. Off until VAPID keys are set
 * (php artisan webpush:vapid → WEB_PUSH_VAPID_PUBLIC / WEB_PUSH_VAPID_PRIVATE / WEB_PUSH_SUBJECT).
 * Sending is queued (SendWebPush); dead subscriptions are removed there.
 */
final class WebPush
{
    public static function enabled(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'))
            && class_exists(\Minishlink\WebPush\WebPush::class);
    }

    public static function toSuperAdmins(string $title, string $body, string $url, string $tag): void
    {
        if (!self::enabled()) {
            return;
        }
        $ids = User::query()->where('role', 'super_admin')->pluck('id')->map(fn ($id) => (int) $id)->all();
        self::toUsers($ids, $title, $body, $url, $tag);
    }

    /** @param list<int> $userIds */
    public static function toUsers(array $userIds, string $title, string $body, string $url, string $tag): void
    {
        if (!self::enabled() || $userIds === []) {
            return;
        }
        try {
            SendWebPush::dispatch($userIds, [
                'title' => mb_substr($title, 0, 120),
                'body' => mb_substr(strip_tags($body), 0, 240),
                'url' => $url,
                'tag' => $tag,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
