<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Signed link from the List-Unsubscribe header / email footer (info & tips only — transactional
 * mail keeps arriving). POST = RFC 8058 one-click from Gmail/Yahoo; GET = the footer link,
 * redirects to the confirmation page.
 */
class EmailUnsubscribeController extends BaseApiController
{
    public function oneClick(Request $request, int $user): JsonResponse
    {
        if (!$request->hasValidSignature() || !$this->unsubscribe($user)) {
            return $this->fail('Tautan tidak valid', ['code' => 'INVALID_LINK'], 403);
        }

        return $this->ok(['unsubscribed' => true], 'Kamu tidak akan menerima email info & tips lagi');
    }

    public function link(Request $request, int $user): RedirectResponse
    {
        $ok = $request->hasValidSignature() && $this->unsubscribe($user);

        return redirect()->away(FrontendUrl::to('/berhenti-langganan?status=' . ($ok ? 'ok' : 'invalid')));
    }

    private function unsubscribe(int $userId): bool
    {
        $user = User::query()->find($userId);
        if (!$user) {
            return false;
        }
        $preferences = is_array($user->email_preferences) ? $user->email_preferences : [];
        $user->forceFill(['email_preferences' => ['marketing' => false] + $preferences])->save();

        return true;
    }
}
