<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * POS audit trail row. Append-only: written only by App\Services\Pos\Audit\ActivityLogger
 * (hash-chained per organization); updates and deletes are refused here and by MySQL triggers.
 */
class PosActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('pos_activity_logs is append-only.'));
        static::deleting(fn () => throw new LogicException('pos_activity_logs is append-only.'));
    }
}
