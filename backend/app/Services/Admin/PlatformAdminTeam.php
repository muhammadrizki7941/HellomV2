<?php

namespace App\Services\Admin;

use App\Jobs\SendPlatformMail;
use App\Models\AuditLog;
use App\Models\PlatformAdminInvitation;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Platform admin team (Super admin › Pengaturan › Tim admin): invite someone by email to become
 * a full super admin, resend/revoke invitations, remove an admin (back to a normal account).
 * New admins come only through an emailed link; the owner email is told about every change.
 */
final class PlatformAdminTeam
{
    public const INVITE_HOURS = 72;

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /** @return array{admins: list<array<string, mixed>>, invitations: list<array<string, mixed>>} */
    public function overview(User $viewer): array
    {
        $admins = User::query()->where('role', 'super_admin')->orderBy('created_at')->get(['id', 'name', 'email', 'created_at']);
        $invitations = PlatformAdminInvitation::query()->whereNull('accepted_at')->whereNull('revoked_at')
            ->with('invitedBy:id,name')->latest('id')->limit(50)->get();

        return [
            'admins' => $admins->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_self' => $u->id === $viewer->id,
                'since' => optional($u->created_at)->toIso8601String(),
            ])->values()->all(),
            'invitations' => $invitations->map(fn (PlatformAdminInvitation $i) => [
                'id' => $i->id, 'email' => $i->email, 'status' => $i->status(), 'expires_at' => $i->expires_at->toIso8601String(),
                'invited_by' => $i->invitedBy?->name,
            ])->values()->all(),
        ];
    }

    public function invite(User $by, string $email): PlatformAdminInvitation
    {
        $email = strtolower(trim($email));
        $existing = User::query()->where('email', $email)->first();
        if ($existing?->role === 'super_admin') {
            throw new AdminTeamException('Email ini sudah menjadi admin.', 'ALREADY_ADMIN');
        }
        if ($existing?->isSuspended()) {
            throw new AdminTeamException('Akun dengan email ini sedang dinonaktifkan. Aktifkan dulu di menu Pengguna.', 'ACCOUNT_SUSPENDED');
        }

        [$invitation, $plain] = DB::transaction(function () use ($by, $email): array {
            // One open invitation per email: older links stop working.
            PlatformAdminInvitation::query()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $plain = Str::random(48);
            $invitation = PlatformAdminInvitation::query()->create([
                'email' => $email, 'token_hash' => hash('sha256', $plain), 'invited_by_user_id' => $by->id,
                'expires_at' => now()->addHours(self::INVITE_HOURS),
            ]);

            return [$invitation, $plain];
        });
        $this->sendInvitation($invitation, $plain, $by, $existing !== null);
        $this->notifications->createPlatformNotice('Undangan admin dikirim', "{$by->name} mengundang {$email} menjadi super admin Hellom. Link berlaku " . self::INVITE_HOURS . ' jam.', '/admin/settings');

        return $invitation;
    }

    public function resend(User $by, PlatformAdminInvitation $invitation): PlatformAdminInvitation
    {
        if (in_array($invitation->status(), ['accepted', 'revoked'], true)) {
            throw new AdminTeamException('Undangan ini sudah tidak aktif. Kirim undangan baru.', 'INVITATION_CLOSED');
        }
        $plain = Str::random(48);
        $invitation->forceFill(['token_hash' => hash('sha256', $plain), 'expires_at' => now()->addHours(self::INVITE_HOURS), 'invited_by_user_id' => $by->id])->save();
        $this->sendInvitation($invitation, $plain, $by, User::query()->where('email', $invitation->email)->exists());

        return $invitation;
    }

    public function revoke(PlatformAdminInvitation $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            throw new AdminTeamException('Undangan sudah diterima. Hapus admin-nya dari daftar admin.', 'INVITATION_CLOSED');
        }
        $invitation->forceFill(['revoked_at' => $invitation->revoked_at ?? now()])->save();
    }

    /** Back to a normal account (role "admin" = owner of their own shops). Never the last admin or yourself. */
    public function remove(User $by, User $target): void
    {
        DB::transaction(function () use ($by, $target): void {
            $admins = User::query()->where('role', 'super_admin')->lockForUpdate()->pluck('id')->all();
            if ($target->id === $by->id) {
                throw new AdminTeamException('Kamu tidak bisa menghapus akunmu sendiri dari admin.', 'CANNOT_REMOVE_SELF');
            }
            if (!in_array($target->id, $admins, true)) {
                throw new AdminTeamException('Akun ini bukan admin.', 'NOT_ADMIN');
            }
            if (count($admins) <= 1) {
                throw new AdminTeamException('Harus ada minimal satu super admin.', 'LAST_ADMIN');
            }
            $target->forceFill(['role' => 'admin'])->save();
        });
        SendPlatformMail::dispatch([(string) $target->email], 'Akses admin Hellom kamu dicabut', [
            'headline' => 'Akses admin dicabut',
            'intro' => "Akses super admin Hellom untuk akun {$target->email} sudah dicabut oleh {$by->name}. Akunmu tetap ada sebagai akun biasa.",
            'closing' => 'Kalau ini tidak seharusnya terjadi, hubungi owner Hellom.',
        ]);
        $this->notifications->createPlatformNotice('Admin dihapus', "{$by->name} mencabut akses super admin {$target->name} ({$target->email}).", '/admin/settings');
    }

    /** @return array{email: string, status: string, has_account: bool, invited_by: ?string, expires_at: string} */
    public function publicView(string $token): array
    {
        $invitation = $this->find($token);

        return [
            'email' => $invitation->email,
            'status' => $invitation->status(),
            'has_account' => User::query()->where('email', $invitation->email)->exists(),
            'invited_by' => $invitation->invitedBy?->name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];
    }

    /** Existing account: confirm with its password. New account: name + password. Then super admin. */
    public function accept(string $token, string $password, ?string $name, ?string $ip = null): User
    {
        $user = DB::transaction(function () use ($token, $password, $name): User {
            $invitation = PlatformAdminInvitation::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (!$invitation || $invitation->status() !== 'pending') {
                throw new AdminTeamException(self::closedMessage($invitation?->status()), 'INVITATION_INVALID');
            }
            $user = User::query()->where('email', $invitation->email)->lockForUpdate()->first();
            if ($user) {
                if (!Hash::check($password, (string) $user->password)) {
                    throw new AdminTeamException('Password salah. Pakai password akun Hellom untuk email ini.', 'INVALID_PASSWORD');
                }
                if ($user->isSuspended()) {
                    throw new AdminTeamException('Akun ini sedang dinonaktifkan.', 'ACCOUNT_SUSPENDED');
                }
                $user->forceFill(['role' => 'super_admin', 'email_verified_at' => $user->email_verified_at ?? now()])->save();
            } else {
                $user = User::query()->create(['name' => trim((string) $name), 'email' => $invitation->email, 'password' => Hash::make($password), 'role' => 'super_admin']);
                $user->forceFill(['email_verified_at' => now()])->save(); // the emailed link proves the inbox
            }
            $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
            AuditLog::record(action: 'admin_team.invitation_accepted', userId: $user->id, entityType: 'user', entityId: $user->id,
                newValues: ['email' => $user->email, 'invited_by_user_id' => $invitation->invited_by_user_id]);

            return $user;
        });
        $this->notifications->createPlatformNotice('Admin baru bergabung', "{$user->name} ({$user->email}) menerima undangan dan sekarang menjadi super admin Hellom.", '/admin/settings');

        return $user;
    }

    private function find(string $token): PlatformAdminInvitation
    {
        $invitation = strlen($token) >= 32 && strlen($token) <= 128
            ? PlatformAdminInvitation::query()->where('token_hash', hash('sha256', $token))->with('invitedBy:id,name')->first() : null;
        if (!$invitation) {
            throw new AdminTeamException('Link undangan tidak dikenal. Minta super admin mengirim ulang undangan.', 'INVITATION_INVALID', 404);
        }

        return $invitation;
    }

    public static function closedMessage(?string $status): string
    {
        return match ($status) {
            'accepted' => 'Undangan ini sudah dipakai. Silakan masuk seperti biasa.',
            'revoked' => 'Undangan ini sudah dibatalkan.',
            'expired' => 'Undangan sudah kedaluwarsa. Minta super admin mengirim ulang.',
            default => 'Link undangan tidak dikenal.',
        };
    }

    private function sendInvitation(PlatformAdminInvitation $invitation, string $plain, User $by, bool $hasAccount): void
    {
        SendPlatformMail::dispatch([$invitation->email], 'Undangan menjadi admin Hellom', [
            'headline' => 'Kamu diundang menjadi admin Hellom',
            'intro' => "{$by->name} mengundang kamu menjadi super admin Hellom — akses penuh ke dashboard admin, termasuk pengaturan pembayaran dan persetujuan penarikan dana. "
                . ($hasAccount ? 'Masuk dengan password akun Hellom kamu untuk menerima.' : 'Buat password untuk akun admin kamu.'),
            'details' => ['Email' => $invitation->email, 'Berlaku sampai' => $invitation->expires_at->timezone('Asia/Jakarta')->format('d M Y H:i') . ' WIB'],
            'cta_url' => FrontendUrl::to('/undangan-admin?' . http_build_query(['token' => $plain])),
            'cta_label' => 'Terima undangan',
            'closing' => 'Tidak mengenal pengirimnya? Abaikan email ini — tidak ada yang berubah tanpa kamu menerima undangan.',
        ]);
    }
}
