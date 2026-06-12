<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberPromotion extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'expires_at',
        'is_redeemed',
        'redeemed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_redeemed' => 'boolean',
        'redeemed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
