<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Emailed POS PIN reset link (SHA-256 of the token, single use). */
class PosOwnerPinReset extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
