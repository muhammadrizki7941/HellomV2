<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\AuditLog;
use App\Support\Pos\BusinessProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosBusinessProfileController extends BasePosController
{
    public function show(Request $request): JsonResponse
    {
        $organization = $this->getOrg($request);
        if (!$organization) {
            return $this->error('Organisasi POS tidak ditemukan.', 'CONTEXT_MISSING', null, 403);
        }

        return $this->success(BusinessProfile::present($organization), 'Profil usaha POS');
    }

    public function update(Request $request): JsonResponse
    {
        $organization = $this->getOrg($request);
        if (!$organization) {
            return $this->error('Organisasi POS tidak ditemukan.', 'CONTEXT_MISSING', null, 403);
        }

        $moduleKeys = array_keys(config('pos_business.modules', []));
        $validated = $request->validate([
            'business_type' => ['sometimes', 'string', Rule::in(array_keys(config('pos_business.types', [])))],
            'onboarding_completed' => ['sometimes', 'boolean'],
            'enabled_modules' => ['sometimes', 'array', 'list'],
            'enabled_modules.*' => ['string', 'distinct', Rule::in($moduleKeys)],
        ], [
            'business_type.in' => 'Jenis usaha tidak dikenal.',
            'enabled_modules.array' => 'Daftar modul harus berupa array.',
            'enabled_modules.list' => 'Daftar modul harus berupa daftar pilihan.',
            'enabled_modules.*.in' => 'Modul yang dipilih tidak dikenal.',
            'enabled_modules.*.distinct' => 'Modul yang sama tidak boleh dipilih lebih dari sekali.',
        ]);

        $before = BusinessProfile::present($organization);
        $currentType = (string) $organization->business_type;
        $businessType = $validated['business_type'] ?? $currentType;
        if (array_key_exists('enabled_modules', $validated)) {
            $enabledModules = array_values(array_unique($validated['enabled_modules']));
        } elseif ($businessType !== $currentType) {
            $enabledModules = BusinessProfile::preset($businessType);
        } else {
            $enabledModules = BusinessProfile::enabledModules($organization);
        }

        $organization->forceFill([
            'business_type' => $businessType,
            'enabled_modules' => $enabledModules,
            'pos_onboarding_completed_at' => ($validated['onboarding_completed'] ?? false)
                ? ($organization->pos_onboarding_completed_at ?? now())
                : $organization->pos_onboarding_completed_at,
        ])->save();

        $after = BusinessProfile::present($organization->fresh());
        AuditLog::record(
            'pos.business_profile_updated',
            $request->user()?->id,
            $organization->id,
            'organization',
            $organization->id,
            $before,
            $after,
            null,
            $request->ip(),
            mb_substr((string) $request->userAgent(), 0, 255)
        );

        return $this->success($after, 'Profil usaha POS disimpan');
    }
}
