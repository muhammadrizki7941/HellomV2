<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerNotification extends Model
{
    protected $table = 'consumer_notifications';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'is_read',
        'read_at',
        'action_type',
        'action_url',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every new dashboard notification rings the bell right away: realtime to the user's room
     * (user_{id}, already in their socket token) and web push — after the surrounding commit.
     */
    protected static function booted(): void
    {
        static::created(function (self $notification) {
            \Illuminate\Support\Facades\DB::afterCommit(function () use ($notification) {
                try {
                    app(\App\Services\Realtime\RealtimeClient::class)->emitToRoom('user_' . $notification->user_id, 'user.notification.created', [
                        'id' => (int) $notification->id,
                        'type' => (string) $notification->type,
                        'title' => (string) $notification->title,
                        'body' => (string) $notification->body,
                        'action_url' => $notification->action_url,
                        'created_at' => $notification->created_at?->toISOString(),
                    ]);
                } catch (\Throwable $e) {
                    report($e);
                }
                \App\Services\Notifications\WebPush::toUsers([(int) $notification->user_id], (string) $notification->title, (string) $notification->body,
                    (string) ($notification->action_url ?: '/dashboard'), 'user-' . $notification->id);
            });
        });
    }
}
