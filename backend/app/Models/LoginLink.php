<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Single-use sign-in link delivered by email. Only the token hash is stored;
 * the plain token exists only in the email.
 */
class LoginLink extends Model
{
    public const TTL_DAYS = 7;

    protected $fillable = [
        'user_id',
        'token_hash',
        'redirect_path',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{0:string,1:self} [plain token, link]
     */
    public static function issue(User $user, ?string $redirectPath = null): array
    {
        $plain = Str::random(64);

        $link = static::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'redirect_path' => $redirectPath,
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        return [$plain, $link];
    }

    public static function findUsable(string $plain): ?self
    {
        if ($plain === '') {
            return null;
        }

        return static::query()
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
