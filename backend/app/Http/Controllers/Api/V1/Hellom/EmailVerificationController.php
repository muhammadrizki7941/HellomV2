<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Jobs\SendPlatformMail;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Email verification (required before a seller withdraws and for the "Penjual
 * Terverifikasi" badge). The link is a signed API URL valid 24 hours; opening it marks
 * the address verified and redirects to the dashboard.
 */
class EmailVerificationController extends BaseApiController
{
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }
        if ($user->email_verified_at !== null) {
            return $this->ok(['email_verified' => true], 'Email kamu sudah terverifikasi');
        }

        $link = URL::temporarySignedRoute('api.v1.hellom.public.email.verify', now()->addDay(), [
            'id' => $user->id,
            'hash' => sha1(strtolower((string) $user->email)),
        ]);
        SendPlatformMail::dispatch([(string) $user->email], 'Verifikasi email akun Hellom kamu', [
            'headline' => 'Verifikasi email kamu',
            'intro' => 'Klik tombol di bawah untuk memastikan email ini milik kamu. Tautan berlaku 24 jam.',
            'cta_url' => $link,
            'cta_label' => 'Verifikasi Email',
            'closing' => 'Bukan kamu yang meminta? Abaikan email ini.',
        ]);

        return $this->ok(['email_verified' => false], 'Link verifikasi sudah dikirim ke ' . $user->email);
    }

    /** Public, signed: the link from the email. */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);
        if (!$request->hasValidSignature() || !$user || !hash_equals(sha1(strtolower((string) $user->email)), $hash)) {
            return redirect()->away(FrontendUrl::to('/dashboard?email_verified=invalid'));
        }
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return redirect()->away(FrontendUrl::to('/dashboard/apps/landing-builder?tab=saldo&email_verified=1'));
    }
}
