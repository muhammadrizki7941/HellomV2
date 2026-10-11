<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The organization owner's POS PIN (hash only). See App\Services\Pos\Audit\OwnerPinService. */
class PosOwnerPin extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['pin_hash'];

    protected $casts = [
        'locked_until' => 'datetime',
        'pin_changed_at' => 'datetime',
        'failed_attempts' => 'integer',
    ];

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
