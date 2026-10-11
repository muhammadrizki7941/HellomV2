<?php

namespace App\Services\Pos\Audit;

use App\Jobs\SendPlatformMail;
use App\Models\Organization;
use App\Models\PosOwnerPin;
use App\Models\PosOwnerPinReset;
use App\Models\User;
use App\Support\FrontendUrl;
use App\Support\Pos\ActivityActions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The organization owner's POS PIN: opens the audit log (POS › Log Audit) and approves risky
 * cashier actions (void, refund). Only organization owners (pivot role "owner") may set or use it
 * for the log; the reset link always goes to the primary owner's email (first owner of the shop).
 *
 * 5 wrong PINs → locked 15 minutes, logged and emailed to the primary owner. Verify outside any
 * DB transaction that may roll back, otherwise the wrong-try counter would roll back with it.
 */
final class OwnerPinService
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;
    public const RESET_MINUTES = 30;
    public const UNLOCK_MINUTES = 15;

    public function __construct(private readonly ActivityLogger $activity)
    {
    }

    public function isOwner(?User $user, Organization $org): bool
    {
        return $user !== null && DB::table('organization_user')->where('organization_id', $org->id)
            ->where('user_id', $user->id)->where('role', 'owner')->exists();
    }

    /** First owner of the organization (lowest membership id) = the "pemilik utama". */
    public function primaryOwner(Organization $org): ?User
    {
        $userId = DB::table('organization_user')->where('organization_id', $org->id)->where('role', 'owner')->orderBy('id')->value('user_id');

        return $userId ? User::query()->find($userId) : null;
    }

    public function pinOf(Organization $org): ?PosOwnerPin
    {
        return PosOwnerPin::query()->where('organization_id', $org->id)->first();
    }

    /** @return array{is_owner: bool, pin_set: bool, locked_until: ?string, reset_email: ?string} */
    public function status(Organization $org, ?User $user): array
    {
        $isOwner = $this->isOwner($user, $org);
        $pin = $this->pinOf($org);

        return [
            'is_owner' => $isOwner,
            'pin_set' => $pin !== null,
            'locked_until' => $pin?->isLocked() ? $pin->locked_until->toIso8601String() : null,
            'reset_email' => $isOwner ? self::maskEmail((string) $this->primaryOwner($org)?->email) : null,
        ];
    }

    public function create(Organization $org, User $user, string $pin): void
    {
        $this->assertOwner($org, $user);
        $this->assertStrong($pin);
        DB::transaction(function () use ($org, $user, $pin): void {
            if (PosOwnerPin::query()->where('organization_id', $org->id)->lockForUpdate()->exists()) {
                throw new OwnerPinException('PIN sudah dibuat. Pakai "Ganti PIN" atau "Lupa PIN".', 'PIN_ALREADY_SET', 409);
            }
            PosOwnerPin::query()->create(['organization_id' => $org->id, 'set_by_user_id' => $user->id, 'pin_hash' => Hash::make($pin), 'pin_changed_at' => now()]);
            $this->activity->record($org->id, ActivityActions::PIN_SET, ['target_type' => 'organization', 'target_id' => $org->id, 'target_label' => $org->name]);
        });
    }

    public function change(Organization $org, User $user, string $currentPin, string $newPin): void
    {
        $this->assertOwner($org, $user);
        $this->verify($org, $currentPin, 'ganti PIN');
        $this->assertStrong($newPin);
        DB::transaction(function () use ($org, $user, $newPin): void {
            PosOwnerPin::query()->where('organization_id', $org->id)->lockForUpdate()->firstOrFail()
                ->forceFill(['pin_hash' => Hash::make($newPin), 'set_by_user_id' => $user->id, 'pin_changed_at' => now(), 'failed_attempts' => 0, 'locked_until' => null])->save();
            $this->activity->record($org->id, ActivityActions::PIN_CHANGED, ['target_type' => 'organization', 'target_id' => $org->id, 'target_label' => $org->name]);
        });
        $this->tellOwner($org, 'PIN POS kamu diganti', 'PIN POS diganti', "PIN pemilik untuk POS {$org->name} baru saja diganti oleh {$user->name}.");
    }

    /**
     * Check a PIN typed for $purpose ("buka log audit", "void pesanan #A-001"). Returns the PIN
     * holder (the approver). Wrong PIN → counted, logged; 5 in a row → locked.
     */
    public function verify(Organization $org, string $pin, string $purpose): User
    {
        $record = $this->pinOf($org);
        if (!$record) {
            throw new OwnerPinException('Pemilik toko belum membuat PIN. Minta pemilik membuka POS dan membuat PIN dulu.', 'PIN_NOT_SET', 409);
        }
        if ($record->isLocked()) {
            throw $this->lockedError($record);
        }

        if (preg_match('/^\d{6}$/', $pin) === 1 && Hash::check($pin, $record->pin_hash)) {
            if ($record->failed_attempts > 0) {
                $record->forceFill(['failed_attempts' => 0])->save();
            }
            $holder = $record->set_by_user_id ? User::query()->find($record->set_by_user_id) : null;

            return $holder ?? $this->primaryOwner($org) ?? throw new OwnerPinException('Pemilik toko tidak ditemukan.', 'OWNER_MISSING', 409);
        }

        [$locked, $left] = DB::transaction(function () use ($org): array {
            $locked = PosOwnerPin::query()->where('organization_id', $org->id)->lockForUpdate()->firstOrFail();
            $attempts = $locked->failed_attempts + 1;
            $lockNow = $attempts >= self::MAX_ATTEMPTS;
            $locked->forceFill([
                'failed_attempts' => $lockNow ? 0 : $attempts,
                'locked_until' => $lockNow ? now()->addMinutes(self::LOCK_MINUTES) : $locked->locked_until,
            ])->save();

            return [$lockNow ? $locked : null, self::MAX_ATTEMPTS - $attempts];
        });
        $this->activity->record($org->id, ActivityActions::PIN_FAILED, ['reason' => "PIN salah saat {$purpose}", 'target_type' => 'organization', 'target_id' => $org->id, 'target_label' => $org->name]);

        if ($locked) {
            $this->activity->record($org->id, ActivityActions::PIN_LOCKED, ['reason' => "PIN salah " . self::MAX_ATTEMPTS . "x berturut-turut saat {$purpose}", 'target_type' => 'organization', 'target_id' => $org->id, 'target_label' => $org->name]);
            $who = request()->user()?->name ?? 'seseorang';
            $this->tellOwner($org, 'PIN POS terkunci', 'PIN POS dikunci sementara',
                "PIN pemilik POS {$org->name} salah dimasukkan " . self::MAX_ATTEMPTS . " kali berturut-turut (terakhir oleh {$who}, saat {$purpose}). PIN dikunci " . self::LOCK_MINUTES . ' menit. Kalau ini bukan kamu atau timmu, ganti PIN setelah kunci terbuka.');
            throw $this->lockedError($locked);
        }

        throw new OwnerPinException(
            $left === 1 ? 'PIN salah. Sisa 1 kali coba sebelum PIN dikunci.' : "PIN salah. Sisa {$left} kali coba.",
            'PIN_INVALID', 422, ['attempts_left' => $left]
        );
    }

    /** Short-lived proof that the owner typed the PIN (sent back as X-Owner-Pin-Token). */
    public function unlock(Organization $org, User $user, string $pin): array
    {
        $this->assertOwner($org, $user);
        $this->verify($org, $pin, 'buka log audit');
        $expires = now()->addMinutes(self::UNLOCK_MINUTES);
        $this->activity->record($org->id, ActivityActions::AUDIT_UNLOCKED, ['target_type' => 'organization', 'target_id' => $org->id, 'target_label' => $org->name]);

        return [
            'token' => Crypt::encryptString(json_encode(['o' => $org->id, 'u' => $user->id, 'e' => $expires->getTimestamp()])),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    public function unlockTokenValid(?string $token, Organization $org, ?User $user): bool
    {
        if (!$token || !$user) {
            return false;
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return false;
        }

        return is_array($data) && (int) ($data['o'] ?? 0) === (int) $org->id && (int) ($data['u'] ?? 0) === (int) $user->id
            && (int) ($data['e'] ?? 0) > now()->getTimestamp() && $this->isOwner($user, $org);
    }

    /** Email a reset link to the primary owner. Returns the masked address. */
    public function requestReset(Organization $org, User $user): string
    {
        $this->assertOwner($org, $user);
        $owner = $this->primaryOwner($org);
        if (!$owner || !$owner->email) {
            throw new OwnerPinException('Email pemilik utama tidak ditemukan.', 'OWNER_EMAIL_MISSING', 409);
        }
        $key = 'pos-pin-reset:' . $org->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw new OwnerPinException('Link reset sudah dikirim beberapa kali. Cek email kamu, atau coba lagi dalam ' . max(1, (int) ceil(RateLimiter::availableIn($key) / 60)) . ' menit.', 'PIN_RESET_THROTTLED', 429);
        }
        RateLimiter::hit($key, 3600);

        $plain = Str::random(48);
        DB::transaction(function () use ($org, $owner, $user, $plain): void {
            PosOwnerPinReset::query()->where('organization_id', $org->id)->whereNull('used_at')->update(['used_at' => now()]);
            PosOwnerPinReset::query()->create([
                'organization_id' => $org->id, 'owner_user_id' => $owner->id, 'requested_by_user_id' => $user->id,
                'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addMinutes(self::RESET_MINUTES),
            ]);
            $this->activity->record($org->id, ActivityActions::PIN_RESET_REQUESTED, ['target_type' => 'organization', 'target_id' => $org->id, 'target_label' => $org->name,
                'after' => ['sent_to' => self::maskEmail((string) $owner->email)]]);
        });
        SendPlatformMail::dispatch([(string) $owner->email], 'Reset PIN POS ' . $org->name, [
            'headline' => 'Buat PIN POS baru',
            'intro' => "Ada permintaan reset PIN pemilik untuk POS {$org->name}. Tekan tombol di bawah untuk membuat PIN baru. Link berlaku " . self::RESET_MINUTES . ' menit dan hanya bisa dipakai sekali.',
            'cta_url' => FrontendUrl::to('/reset-pin-pos?token=' . $plain),
            'cta_label' => 'Buat PIN baru',
            'closing' => 'Tidak merasa meminta ini? Abaikan email ini — PIN kamu tidak berubah. Kalau sering terjadi, cek siapa saja yang punya akses akun pemilik.',
        ]);

        return self::maskEmail((string) $owner->email);
    }

    /** @return array{organization: string, expires_at: string} */
    public function resetInfo(string $token): array
    {
        $reset = $this->findReset($token);

        return ['organization' => (string) Organization::query()->whereKey($reset->organization_id)->value('name'), 'expires_at' => $reset->expires_at->toIso8601String()];
    }

    public function resetWithToken(string $token, string $pin): Organization
    {
        $this->assertStrong($pin);
        $org = DB::transaction(function () use ($token, $pin): Organization {
            $reset = PosOwnerPinReset::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (!$reset || !$reset->isUsable()) {
                throw new OwnerPinException('Link reset sudah tidak berlaku. Minta link baru dari POS › Log Audit › Lupa PIN.', 'PIN_RESET_INVALID', 410);
            }
            $org = Organization::query()->findOrFail($reset->organization_id);
            $owner = User::query()->findOrFail($reset->owner_user_id);
            $reset->forceFill(['used_at' => now()])->save();
            $values = ['pin_hash' => Hash::make($pin), 'set_by_user_id' => $owner->id, 'pin_changed_at' => now(), 'failed_attempts' => 0, 'locked_until' => null];
            $existing = PosOwnerPin::query()->where('organization_id', $org->id)->lockForUpdate()->first();
            $existing ? $existing->forceFill($values)->save() : PosOwnerPin::query()->create(['organization_id' => $org->id] + $values);
            $this->activity->record($org->id, ActivityActions::PIN_RESET, ['actor' => $owner, 'actor_role' => 'owner', 'target_type' => 'organization', 'target_id' => $org->id, 'target_label' => $org->name]);

            return $org;
        });
        $this->tellOwner($org, 'PIN POS kamu sudah direset', 'PIN POS direset', "PIN pemilik untuk POS {$org->name} baru saja dibuat ulang lewat link reset di email.");

        return $org;
    }

    public static function maskEmail(string $email): ?string
    {
        if (!str_contains($email, '@')) {
            return null;
        }
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, min(2, mb_strlen($name))) . str_repeat('•', max(3, mb_strlen($name) - 2)) . '@' . $domain;
    }

    private function findReset(string $token): PosOwnerPinReset
    {
        $reset = strlen($token) >= 32 && strlen($token) <= 128 ? PosOwnerPinReset::query()->where('token_hash', hash('sha256', $token))->first() : null;
        if (!$reset || !$reset->isUsable()) {
            throw new OwnerPinException('Link reset sudah tidak berlaku. Minta link baru dari POS › Log Audit › Lupa PIN.', 'PIN_RESET_INVALID', 410);
        }

        return $reset;
    }

    private function assertOwner(Organization $org, User $user): void
    {
        if (!$this->isOwner($user, $org)) {
            throw new OwnerPinException('Hanya pemilik toko yang bisa mengatur PIN dan membuka log audit.', 'OWNER_ONLY', 403);
        }
    }

    private function assertStrong(string $pin): void
    {
        if (preg_match('/^\d{6}$/', $pin) !== 1) {
            throw new OwnerPinException('PIN harus 6 angka.', 'PIN_FORMAT', 422);
        }
        $digits = array_map('intval', str_split($pin));
        $same = count(array_unique($digits)) === 1;
        $up = $down = true;
        for ($i = 1; $i < 6; $i++) {
            $up = $up && $digits[$i] === ($digits[$i - 1] + 1) % 10;
            $down = $down && $digits[$i] === ($digits[$i - 1] + 9) % 10;
        }
        if ($same || $up || $down) {
            throw new OwnerPinException('PIN terlalu mudah ditebak (angka sama atau berurutan). Pilih kombinasi lain.', 'PIN_WEAK', 422);
        }
    }

    private function lockedError(PosOwnerPin $pin): OwnerPinException
    {
        $minutes = max(1, (int) ceil(now()->diffInSeconds($pin->locked_until, true) / 60));

        return new OwnerPinException("PIN dikunci karena salah " . self::MAX_ATTEMPTS . " kali. Coba lagi dalam {$minutes} menit, atau reset lewat email pemilik.", 'PIN_LOCKED', 423,
            ['locked_until' => $pin->locked_until?->toIso8601String()]);
    }

    private function tellOwner(Organization $org, string $subject, string $headline, string $intro): void
    {
        $email = $this->primaryOwner($org)?->email;
        if ($email) {
            SendPlatformMail::dispatch([(string) $email], $subject, ['headline' => $headline, 'intro' => $intro,
                'closing' => 'Kalau ini bukan kamu, segera reset PIN dari POS › Log Audit dan ganti password akun.']);
        }
    }
}
