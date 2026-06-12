<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OwnerNotification extends Model
{
    protected $fillable = [
        'type',
        'title',
        'message',
        'data',
        'is_read',
        'action_type',
        'action_url',
        'action_status',
        'action_done_at',
        'reference_id',
        'reference_type',
        'notifiable_id',
        'notifiable_type',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'action_done_at' => 'datetime',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
