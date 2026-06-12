<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserOnboardingProgress extends Model
{
    use HasFactory;

    protected $table = 'user_onboarding_progress';

    protected $fillable = [
        'user_id',
        'dismissed',
        'dismissed_at',
    ];

    protected $casts = [
        'dismissed' => 'boolean',
        'dismissed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
