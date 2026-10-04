<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\PlatformAdminInvitation;
use App\Models\User;
use App\Services\Admin\AdminTeamException;
use App\Services\Admin\PlatformAdminTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Super admin › Pengaturan › Tim admin: list admins, invite by email, resend/revoke, remove.
 * Invite and remove need the signed-in admin's password. Public side: view + accept an invitation.
 */
class AdminTeamController extends BaseApiController
{
    public function __construct(private readonly PlatformAdminTeam $team)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->ok($this->team->overview($request->user()), 'Tim admin');
    }

    public function invite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
        ], ['email.email' => 'Format email belum benar.', 'password.required' => 'Masukkan password kamu untuk konfirmasi.']);
        $this->confirmPassword($request);

        return $this->run(function () use ($request, $validated) {
            $invitation = $this->team->invite($request->user(), (string) $validated['email']);
            $this->adminAudit($request, 'admin_team.invited', 'platform_admin_invitation', $invitation->id, ['email' => $invitation->email]);

            return $this->ok($this->team->overview($request->user()), 'Undangan dikirim ke ' . $invitation->email);
        });
    }

    public function resend(Request $request, int $invitationId): JsonResponse
    {
        $invitation = PlatformAdminInvitation::query()->findOrFail($invitationId);

        return $this->run(function () use ($request, $invitation) {
            $this->team->resend($request->user(), $invitation);
            $this->adminAudit($request, 'admin_team.invitation_resent', 'platform_admin_invitation', $invitation->id, ['email' => $invitation->email]);

            return $this->ok($this->team->overview($request->user()), 'Undangan dikirim ulang');
        });
    }

    public function revoke(Request $request, int $invitationId): JsonResponse
    {
        $invitation = PlatformAdminInvitation::query()->findOrFail($invitationId);

        return $this->run(function () use ($request, $invitation) {
            $this->team->revoke($invitation);
            $this->adminAudit($request, 'admin_team.invitation_revoked', 'platform_admin_invitation', $invitation->id, ['email' => $invitation->email]);

            return $this->ok($this->team->overview($request->user()), 'Undangan dibatalkan');
        });
    }

    public function remove(Request $request, int $userId): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:255']], ['password.required' => 'Masukkan password kamu untuk konfirmasi.']);
        $this->confirmPassword($request);
        $target = User::query()->findOrFail($userId);

        return $this->run(function () use ($request, $target) {
            $this->team->remove($request->user(), $target);
            $this->adminAudit($request, 'admin_team.removed', 'user', $target->id, ['email' => $target->email, 'role' => ['super_admin', 'admin']]);

            return $this->ok($this->team->overview($request->user()), 'Akses admin dicabut');
        });
    }

    /** Public: what the invitation page shows. The token is the secret (only in the email). */
    public function publicShow(string $token): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->team->publicView($token), 'Undangan admin'));
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $view = $this->run(fn () => $this->team->publicView($token));
        if ($view instanceof JsonResponse) {
            return $view;
        }
        if ($view['status'] !== 'pending') { // expired/used/cancelled: say so before checking the form
            return $this->fail(PlatformAdminTeam::closedMessage($view['status']), ['code' => 'INVITATION_INVALID'], 422);
        }
        $rules = ['password' => ['required', 'string', 'max:255']];
        if (!$view['has_account']) {
            $rules = ['name' => ['required', 'string', 'min:2', 'max:120'], 'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed']];
        }
        $validated = $request->validate($rules, [
            'password.min' => 'Password minimal 8 karakter.', 'password.confirmed' => 'Konfirmasi password belum sama.', 'name.required' => 'Isi nama kamu.',
        ]);

        return $this->run(function () use ($request, $token, $validated) {
            $user = $this->team->accept($token, (string) $validated['password'], $validated['name'] ?? null, $request->ip());

            return $this->ok(['email' => $user->email, 'role' => $user->role], 'Selamat, kamu sekarang admin Hellom');
        });
    }

    private function confirmPassword(Request $request): void
    {
        if (!Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Password kamu salah.']);
        }
    }

    /** @param callable(): mixed $job */
    private function run(callable $job): mixed
    {
        try {
            return $job();
        } catch (AdminTeamException $e) {
            return $this->fail($e->getMessage(), ['code' => $e->errorCode], $e->status);
        }
    }
}
