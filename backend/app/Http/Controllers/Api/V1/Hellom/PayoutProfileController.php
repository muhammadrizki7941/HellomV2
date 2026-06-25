<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\Organization;
use App\Models\OrganizationPayoutProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayoutProfileController extends BaseApiController
{
    /** Member: view own organization's KYC / payout profile + status. */
    public function show(Request $request): JsonResponse
    {
        [$organization, $role, $error] = $this->resolveOrg($request);
        if ($error) {
            return $error;
        }

        $profile = OrganizationPayoutProfile::query()->where('organization_id', (int) $organization->id)->first();

        return $this->ok([
            'requester_role' => $role,
            'profile' => $profile instanceof OrganizationPayoutProfile ? $this->memberPayload($profile) : null,
            'status' => $profile instanceof OrganizationPayoutProfile ? (string) $profile->status : OrganizationPayoutProfile::STATUS_UNVERIFIED,
        ], 'Payout profile');
    }

    /** Member (owner/admin): submit or update KYC details → status pending. */
    public function submit(Request $request): JsonResponse
    {
        [$organization, $role, $error] = $this->resolveOrg($request);
        if ($error) {
            return $error;
        }

        if (!in_array($role, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin can submit verification', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'nik' => ['required', 'string', 'regex:/^\d{16}$/'],
            'bank_code' => ['required', 'string', 'max:30'],
            'bank_name' => ['nullable', 'string', 'max:80'],
            'account_number' => ['required', 'string', 'max:50'],
            'account_name' => ['required', 'string', 'max:120'],
            'ktp_image' => ['nullable', 'file', 'max:4096', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $profile = OrganizationPayoutProfile::query()->firstOrNew(['organization_id' => (int) $organization->id]);

        // Re-submitting is only blocked while already verified or awaiting review.
        if (in_array((string) $profile->status, [OrganizationPayoutProfile::STATUS_PENDING], true)) {
            return $this->fail('Verifikasi sedang ditinjau, mohon tunggu.', ['code' => 'KYC_UNDER_REVIEW'], 422);
        }

        if (isset($validated['ktp_image']) && $validated['ktp_image'] instanceof UploadedFile) {
            // Remove previous private image if any.
            if ($profile->ktp_image_path && $profile->ktp_image_disk) {
                Storage::disk((string) $profile->ktp_image_disk)->delete((string) $profile->ktp_image_path);
            }
            $path = $validated['ktp_image']->store(sprintf('payout-kyc/%d', (int) $organization->id), 'local');
            $profile->ktp_image_disk = 'local';
            $profile->ktp_image_path = $path;
        }

        $profile->fill([
            'full_name' => (string) $validated['full_name'],
            'nik' => (string) $validated['nik'],
            'bank_code' => (string) $validated['bank_code'],
            'bank_name' => isset($validated['bank_name']) ? (string) $validated['bank_name'] : $profile->bank_name,
            'account_number' => (string) $validated['account_number'],
            'account_name' => (string) $validated['account_name'],
            'submitted_by_user_id' => (int) $request->user()->id,
            'status' => OrganizationPayoutProfile::STATUS_PENDING,
            'review_notes' => null,
            'submitted_at' => now(),
        ]);
        $profile->organization_id = (int) $organization->id;
        $profile->save();

        return $this->ok([
            'profile' => $this->memberPayload($profile),
            'status' => (string) $profile->status,
        ], 'Verifikasi terkirim dan menunggu peninjauan admin', 201);
    }

    /** Super admin: list KYC submissions (default: pending). */
    public function adminIndex(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->fail('Only super admin can review verifications', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:unverified,pending,verified,rejected'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 30);
        $query = OrganizationPayoutProfile::query()->with('organization')->orderByDesc('submitted_at');
        $query->where('status', $validated['status'] ?? OrganizationPayoutProfile::STATUS_PENDING);

        $items = $query->limit($limit)->get()
            ->map(fn(OrganizationPayoutProfile $profile) => $this->adminPayload($profile))
            ->values();

        return $this->ok(['items' => $items], 'Payout profile review queue');
    }

    /** Super admin: approve a KYC submission → verified. */
    public function approve(Request $request, int $profileId): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->fail('Only super admin can approve verifications', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $profile = OrganizationPayoutProfile::query()->find($profileId);
        if (!$profile instanceof OrganizationPayoutProfile) {
            return $this->fail('Payout profile not found', ['code' => 'PROFILE_NOT_FOUND'], 404);
        }

        $profile->forceFill([
            'status' => OrganizationPayoutProfile::STATUS_VERIFIED,
            'reviewed_by_user_id' => (int) $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => (string) $request->input('notes', '') ?: null,
        ])->save();

        return $this->ok(['profile' => $this->adminPayload($profile)], 'Verifikasi disetujui');
    }

    /** Super admin: reject a KYC submission → rejected (seller may resubmit). */
    public function reject(Request $request, int $profileId): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->fail('Only super admin can reject verifications', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:500'],
        ]);

        $profile = OrganizationPayoutProfile::query()->find($profileId);
        if (!$profile instanceof OrganizationPayoutProfile) {
            return $this->fail('Payout profile not found', ['code' => 'PROFILE_NOT_FOUND'], 404);
        }

        $profile->forceFill([
            'status' => OrganizationPayoutProfile::STATUS_REJECTED,
            'reviewed_by_user_id' => (int) $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => (string) $validated['notes'],
        ])->save();

        return $this->ok(['profile' => $this->adminPayload($profile)], 'Verifikasi ditolak');
    }

    /** Super admin: stream the private KTP image for review (PII — never public). */
    public function ktpImage(Request $request, int $profileId): StreamedResponse|JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->fail('Only super admin can view KTP image', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $profile = OrganizationPayoutProfile::query()->find($profileId);
        if (!$profile instanceof OrganizationPayoutProfile || !$profile->ktp_image_path) {
            return $this->fail('KTP image not found', ['code' => 'KTP_IMAGE_NOT_FOUND'], 404);
        }

        $disk = Storage::disk((string) ($profile->ktp_image_disk ?: 'local'));
        if (!$disk->exists((string) $profile->ktp_image_path)) {
            return $this->fail('KTP image not found', ['code' => 'KTP_IMAGE_NOT_FOUND'], 404);
        }

        return $disk->response((string) $profile->ktp_image_path, null, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    // ─── Helpers ───

    /** @return array{0:?Organization,1:?string,2:?JsonResponse} */
    private function resolveOrg(Request $request): array
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return [null, null, $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401)];
        }

        $orgId = (int) ($user->current_organization_id ?? 0);
        if ($orgId <= 0) {
            return [null, null, $this->fail('No active organization selected', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403)];
        }

        if (in_array((string) ($user->role ?? ''), ['admin', 'super_admin'], true)) {
            $organization = Organization::query()->find($orgId);
            if (!$organization instanceof Organization) {
                return [null, null, $this->fail('Current organization not found', ['code' => 'ORG_NOT_FOUND'], 404)];
            }

            return [$organization, (string) $user->role === 'super_admin' ? 'super_admin' : 'owner', null];
        }

        $organization = $user->organizations()->where('organizations.id', $orgId)->first();
        if (!$organization instanceof Organization) {
            return [null, null, $this->fail('Current organization not found in your access list', ['code' => 'ORG_NOT_ACCESSIBLE'], 404)];
        }

        return [$organization, (string) ($organization->pivot->role ?? 'member'), null];
    }

    private function isSuperAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && (string) ($user->role ?? '') === 'super_admin';
    }

    private function maskTail(?string $value, int $visible = 4): ?string
    {
        $value = (string) $value;
        if ($value === '') {
            return null;
        }
        $len = strlen($value);
        if ($len <= $visible) {
            return $value;
        }

        return str_repeat('*', $len - $visible) . substr($value, -$visible);
    }

    private function memberPayload(OrganizationPayoutProfile $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'status' => (string) $profile->status,
            'full_name' => $profile->full_name,
            'nik_masked' => $this->maskTail($profile->nik, 4),
            'bank_code' => $profile->bank_code,
            'bank_name' => $profile->bank_name,
            'account_number_masked' => $this->maskTail($profile->account_number, 4),
            'account_name' => $profile->account_name,
            'has_ktp_image' => (bool) $profile->ktp_image_path,
            'review_notes' => $profile->review_notes,
            'submitted_at' => $profile->submitted_at,
            'reviewed_at' => $profile->reviewed_at,
        ];
    }

    private function adminPayload(OrganizationPayoutProfile $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'organization' => $profile->organization ? [
                'id' => (int) $profile->organization->id,
                'name' => (string) $profile->organization->name,
                'slug' => (string) $profile->organization->slug,
            ] : null,
            'status' => (string) $profile->status,
            'full_name' => $profile->full_name,
            'nik' => $profile->nik,
            'bank_code' => $profile->bank_code,
            'bank_name' => $profile->bank_name,
            'account_number' => $profile->account_number,
            'account_name' => $profile->account_name,
            'has_ktp_image' => (bool) $profile->ktp_image_path,
            'ktp_image_url' => $profile->ktp_image_path
                ? route('api.v1.hellom.admin.payout_profiles.ktp', ['profileId' => (int) $profile->id])
                : null,
            'review_notes' => $profile->review_notes,
            'submitted_at' => $profile->submitted_at,
            'reviewed_at' => $profile->reviewed_at,
        ];
    }
}
