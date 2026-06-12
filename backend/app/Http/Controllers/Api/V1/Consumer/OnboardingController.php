<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Models\OnboardingTip;
use App\Models\User;
use App\Models\UserOnboardingProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends BaseApiController
{
    public function tips(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $progress = UserOnboardingProgress::query()
            ->where('user_id', $user->id)
            ->first();

        if ($progress && $progress->dismissed) {
            return $this->ok([
                'dismissed' => true,
                'tips' => [],
            ], 'Onboarding dismissed');
        }

        $tips = OnboardingTip::query()->active()->get();

        return $this->ok([
            'dismissed' => false,
            'tips' => $tips,
        ], 'Onboarding tips');
    }

    public function dismiss(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        UserOnboardingProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['dismissed' => true, 'dismissed_at' => now()]
        );

        return $this->ok(true, 'Onboarding dismissed');
    }
}
