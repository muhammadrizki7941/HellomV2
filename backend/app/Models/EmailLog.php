<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/** One email attempt from PlatformMailService (Pengaturan Email › Riwayat). Kept 90 days. */
class EmailLog extends Model
{
    use Prunable;

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = ['to_email', 'subject', 'mailable', 'category', 'status', 'error', 'message_id', 'payload', 'attempts'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted', 'attempts' => 'integer'];
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(90));
    }
}
