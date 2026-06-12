<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Mail\OrganizationTeamInvitationMail;
use App\Models\OrganizationTeamInvitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Hellom\PlatformMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class OrganizationTeamController extends BaseApiController
{
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const RESEND_RATE_LIMIT_MAX = 5;
    private const RESEND_RATE_LIMIT_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly PlatformMailService $platformMailService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        $members = $organization->users()
            ->select('users.id', 'users.name', 'users.email', 'organization_user.role', 'organization_user.created_at')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get()
            ->map(fn(User $member): array => $this->memberPayload($member, (string) ($member->pivot->role ?? 'member')))
            ->values();

        return $this->ok([
            'organization' => [
                'id' => (int) $organization->id,
                'name' => (string) $organization->name,
                'slug' => (string) $organization->slug,
            ],
            'requester_role' => $requesterRole,
            'items' => $members,
        ], 'Organization team members');
    }

    public function invite(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin can invite members', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['nullable', 'in:admin,member,super_admin'],
        ]);

        $email = strtolower((string) $validated['email']);
        $targetRole = (string) ($validated['role'] ?? 'member');

        $member = User::query()->where('email', $email)->first();
        if (!$member) {
            return $this->fail('User not found. User must register first.', ['code' => 'USER_NOT_FOUND'], 404);
        }

        if ($this->belongsToDifferentOrganization($member, (int) $organization->id)) {
            return $this->fail(
                'User sudah terhubung ke organisasi lain dan tidak boleh memiliki akses lintas organisasi.',
                ['code' => 'USER_HAS_OTHER_ORGANIZATION'],
                409
            );
        }

        $alreadyMember = $organization->users()->where('users.id', (int) $member->id)->exists();
        if ($alreadyMember) {
            return $this->fail('User is already a member of this organization', ['code' => 'ALREADY_MEMBER'], 409);
        }

        $organization->users()->attach((int) $member->id, ['role' => $targetRole]);

        $freshMember = $organization->users()
            ->where('users.id', (int) $member->id)
            ->first();

        return $this->ok([
            'organization_id' => (int) $organization->id,
            'member' => $freshMember ? $this->memberPayload($freshMember, (string) ($freshMember->pivot->role ?? 'member')) : null,
        ], 'Member invited', 201);
    }

    public function updateRole(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin can update member role', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'role' => ['required', 'in:admin,member,super_admin'],
        ]);

        $member = $organization->users()->where('users.id', $userId)->first();
        if (!$member) {
            return $this->fail('Member not found in current organization', ['code' => 'MEMBER_NOT_FOUND'], 404);
        }

        $currentRole = (string) ($member->pivot->role ?? 'member');
        if ($currentRole === 'owner') {
            return $this->fail('Owner role cannot be changed with this endpoint', ['code' => 'OWNER_ROLE_LOCKED'], 422);
        }

        $newRole = (string) $validated['role'];
        $organization->users()->updateExistingPivot((int) $member->id, ['role' => $newRole]);

        $freshMember = $organization->users()->where('users.id', $userId)->first();

        return $this->ok([
            'organization_id' => (int) $organization->id,
            'member' => $freshMember ? $this->memberPayload($freshMember, (string) ($freshMember->pivot->role ?? 'member')) : null,
        ], 'Member role updated');
    }

    public function destroy(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin can remove members', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        if ($userId === (int) $user->id) {
            return $this->fail('You cannot remove yourself from current organization via this endpoint', ['code' => 'CANNOT_REMOVE_SELF'], 422);
        }

        $member = $organization->users()->where('users.id', $userId)->first();
        if (!$member) {
            return $this->fail('Member not found in current organization', ['code' => 'MEMBER_NOT_FOUND'], 404);
        }

        $memberRole = (string) ($member->pivot->role ?? 'member');
        if ($memberRole === 'owner') {
            return $this->fail('Owner cannot be removed with this endpoint', ['code' => 'OWNER_REMOVE_LOCKED'], 422);
        }

        $organization->users()->detach($userId);

        $target = User::query()->find($userId);
        if ($target && (int) ($target->current_organization_id ?? 0) === (int) $organization->id) {
            $nextOrgId = $target->organizations()
                ->where('organizations.id', '!=', (int) $organization->id)
                ->value('organizations.id');

            $target->forceFill([
                'current_organization_id' => $nextOrgId ? (int) $nextOrgId : null,
            ])->save();
        }

        return $this->ok([
            'organization_id' => (int) $organization->id,
            'removed_user_id' => $userId,
        ], 'Member removed');
    }

    public function listInvitations(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin can view invitations', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,accepted,revoked,expired'],
            'email' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = OrganizationTeamInvitation::query()
            ->where('organization_id', (int) $organization->id);

        if (!empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        if (!empty($validated['email'])) {
            $query->where('email', 'like', '%' . strtolower((string) $validated['email']) . '%');
        }

        $limit = (int) ($validated['limit'] ?? 50);
        $cursor = isset($validated['cursor']) ? (int) $validated['cursor'] : null;

        if ($cursor !== null) {
            $query->where('id', '<', $cursor);
        }

        $invitations = $query
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $invitations->count() > $limit;
        $itemsCollection = $hasMore ? $invitations->take($limit) : $invitations;
        $nextCursor = $hasMore ? (int) ($itemsCollection->last()?->id ?? 0) : null;

        $items = $itemsCollection
            ->map(fn(OrganizationTeamInvitation $invitation): array => $this->invitationPayload($invitation, false))
            ->values();

        return $this->ok([
            'organization' => [
                'id' => (int) $organization->id,
                'name' => (string) $organization->name,
                'slug' => (string) $organization->slug,
            ],
            'filters' => [
                'status' => $validated['status'] ?? null,
                'email' => isset($validated['email']) ? strtolower((string) $validated['email']) : null,
                'limit' => $limit,
                'cursor' => $cursor,
            ],
            'pagination' => [
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
            'items' => $items,
        ], 'Organization invitations');
    }

    public function showInvitation(Request $request, int $invitationId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin can view invitation detail', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $invitation = OrganizationTeamInvitation::query()
            ->where('organization_id', (int) $organization->id)
            ->where('id', $invitationId)
            ->first();

        if (!$invitation instanceof OrganizationTeamInvitation) {
            return $this->fail('Invitation not found', ['code' => 'INVITATION_NOT_FOUND'], 404);
        }

        return $this->ok([
            'organization' => [
                'id' => (int) $organization->id,
                'name' => (string) $organization->name,
                'slug' => (string) $organization->slug,
            ],
            'invitation' => $this->invitationPayload($invitation, false),
        ], 'Invitation detail');
    }

    public function bulkRevokeInvitations(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin can bulk revoke invitations', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'invitation_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invitation_ids.*' => ['integer', 'min:1'],
        ]);

        $invitationIds = collect($validated['invitation_ids'])
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $invitations = OrganizationTeamInvitation::query()
            ->where('organization_id', (int) $organization->id)
            ->whereIn('id', $invitationIds->all())
            ->get()
            ->keyBy('id');

        $revokedIds = [];
        $skipped = [];

        foreach ($invitationIds as $invitationId) {
            $invitation = $invitations->get($invitationId);
            if (!$invitation instanceof OrganizationTeamInvitation) {
                $skipped[] = [
                    'invitation_id' => $invitationId,
                    'reason' => 'not_found',
                ];
                continue;
            }

            if ($invitation->status !== OrganizationTeamInvitation::STATUS_PENDING) {
                $skipped[] = [
                    'invitation_id' => $invitationId,
                    'reason' => 'not_pending',
                    'status' => $invitation->status,
                ];
                continue;
            }

            $invitation->forceFill([
                'status' => OrganizationTeamInvitation::STATUS_REVOKED,
            ])->save();

            $revokedIds[] = $invitationId;
        }

        return $this->ok([
            'organization_id' => (int) $organization->id,
            'summary' => [
                'requested_count' => $invitationIds->count(),
                'revoked_count' => count($revokedIds),
                'skipped_count' => count($skipped),
            ],
            'revoked_invitation_ids' => $revokedIds,
            'skipped' => $skipped,
        ], 'Bulk revoke invitations completed');
    }

    public function bulkResendInvitations(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin can bulk resend invitations', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'invitation_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invitation_ids.*' => ['integer', 'min:1'],
        ]);

        $invitationIds = collect($validated['invitation_ids'])
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $invitations = OrganizationTeamInvitation::query()
            ->where('organization_id', (int) $organization->id)
            ->whereIn('id', $invitationIds->all())
            ->get()
            ->keyBy('id');

        $resent = [];
        $skipped = [];

        foreach ($invitationIds as $invitationId) {
            $invitation = $invitations->get($invitationId);
            if (!$invitation instanceof OrganizationTeamInvitation) {
                $skipped[] = [
                    'invitation_id' => $invitationId,
                    'reason' => 'not_found',
                ];
                continue;
            }

            if ($invitation->status !== OrganizationTeamInvitation::STATUS_PENDING) {
                $skipped[] = [
                    'invitation_id' => $invitationId,
                    'reason' => 'not_pending',
                    'status' => $invitation->status,
                ];
                continue;
            }

            if ($invitation->expires_at && $invitation->expires_at->isPast()) {
                $invitation->forceFill([
                    'status' => OrganizationTeamInvitation::STATUS_EXPIRED,
                ])->save();

                $skipped[] = [
                    'invitation_id' => $invitationId,
                    'reason' => 'expired',
                ];
                continue;
            }

            $rateGuard = $this->checkResendRateGuard($invitation);
            if ($rateGuard !== null) {
                $skipped[] = [
                    'invitation_id' => $invitationId,
                    'reason' => $rateGuard['code'] === 'INVITATION_RESEND_COOLDOWN' ? 'cooldown' : 'rate_limit',
                    'error_code' => $rateGuard['code'],
                    'retry_after_seconds' => $rateGuard['retry_after_seconds'],
                ];
                continue;
            }

            $this->consumeResendRateGuard($invitation);

            $plainToken = Str::random(48);
            $invitation->forceFill([
                'token_hash' => hash('sha256', $plainToken),
            ])->save();

            $emailDelivery = $this->sendInvitationEmail($organization, $invitation, $plainToken);

            $resent[] = [
                'invitation_id' => $invitationId,
                'email' => (string) $invitation->email,
                'email_delivery' => $emailDelivery,
            ];
        }

        return $this->ok([
            'organization_id' => (int) $organization->id,
            'summary' => [
                'requested_count' => $invitationIds->count(),
                'resent_count' => count($resent),
                'skipped_count' => count($skipped),
            ],
            'resent' => $resent,
            'skipped' => $skipped,
            'resend_policy' => [
                'cooldown_seconds' => self::RESEND_COOLDOWN_SECONDS,
                'rate_limit_max' => self::RESEND_RATE_LIMIT_MAX,
                'rate_limit_window_seconds' => self::RESEND_RATE_LIMIT_WINDOW_SECONDS,
            ],
        ], 'Bulk resend invitations completed');
    }

    public function inviteByToken(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin can create invitation link', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['nullable', 'in:admin,member,super_admin'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $email = strtolower((string) $validated['email']);
        $targetRole = (string) ($validated['role'] ?? 'member');
        $expiresInDays = (int) ($validated['expires_in_days'] ?? 7);

        $alreadyMember = $organization->users()->where('users.email', $email)->exists();
        if ($alreadyMember) {
            return $this->fail('User is already a member of this organization', ['code' => 'ALREADY_MEMBER'], 409);
        }

        $existingUser = User::query()->where('email', $email)->first();
        if ($existingUser instanceof User && $this->belongsToDifferentOrganization($existingUser, (int) $organization->id)) {
            return $this->fail(
                'Akun tujuan sudah terhubung ke organisasi lain dan tidak boleh menerima akses tambahan.',
                ['code' => 'USER_HAS_OTHER_ORGANIZATION'],
                409
            );
        }

        $pendingExisting = OrganizationTeamInvitation::query()
            ->where('organization_id', (int) $organization->id)
            ->where('email', $email)
            ->where('status', OrganizationTeamInvitation::STATUS_PENDING)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();

        if ($pendingExisting instanceof OrganizationTeamInvitation) {
            return $this->fail('Pending invitation already exists for this email', [
                'code' => 'INVITATION_ALREADY_PENDING',
                'invitation' => $this->invitationPayload($pendingExisting, false),
            ], 409);
        }

        $plainToken = Str::random(48);

        $invitation = OrganizationTeamInvitation::query()->create([
            'organization_id' => (int) $organization->id,
            'email' => $email,
            'role' => $targetRole,
            'token_hash' => hash('sha256', $plainToken),
            'invited_by_user_id' => (int) $user->id,
            'status' => OrganizationTeamInvitation::STATUS_PENDING,
            'expires_at' => now()->addDays($expiresInDays),
        ]);

        $emailDelivery = $this->sendInvitationEmail($organization, $invitation, $plainToken);

        return $this->ok([
            'organization_id' => (int) $organization->id,
            'invitation' => $this->invitationPayload($invitation, true, $plainToken),
            'email_delivery' => $emailDelivery,
        ], 'Invitation link created', 201);
    }

    public function resendInvitation(Request $request, int $invitationId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin can resend invitation', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $invitation = OrganizationTeamInvitation::query()
            ->where('organization_id', (int) $organization->id)
            ->where('id', $invitationId)
            ->first();

        if (!$invitation instanceof OrganizationTeamInvitation) {
            return $this->fail('Invitation not found', ['code' => 'INVITATION_NOT_FOUND'], 404);
        }

        if ($invitation->status !== OrganizationTeamInvitation::STATUS_PENDING) {
            return $this->fail('Only pending invitation can be resent', ['code' => 'INVITATION_NOT_PENDING'], 422);
        }

        if ($invitation->expires_at && $invitation->expires_at->isPast()) {
            $invitation->forceFill([
                'status' => OrganizationTeamInvitation::STATUS_EXPIRED,
            ])->save();

            return $this->fail('Invitation has expired', ['code' => 'INVITATION_EXPIRED'], 422);
        }

        $rateGuard = $this->checkResendRateGuard($invitation);
        if ($rateGuard !== null) {
            $isCooldown = $rateGuard['code'] === 'INVITATION_RESEND_COOLDOWN';

            return $this->fail(
                $isCooldown ? 'Invitation resend is in cooldown period' : 'Invitation resend rate limit exceeded',
                $isCooldown
                    ? [
                        'code' => $rateGuard['code'],
                        'retry_after_seconds' => $rateGuard['retry_after_seconds'],
                        'cooldown_seconds' => self::RESEND_COOLDOWN_SECONDS,
                    ]
                    : [
                        'code' => $rateGuard['code'],
                        'retry_after_seconds' => $rateGuard['retry_after_seconds'],
                        'limit' => self::RESEND_RATE_LIMIT_MAX,
                        'window_seconds' => self::RESEND_RATE_LIMIT_WINDOW_SECONDS,
                    ],
                429
            );
        }

        $this->consumeResendRateGuard($invitation);

        $plainToken = Str::random(48);
        $invitation->forceFill([
            'token_hash' => hash('sha256', $plainToken),
        ])->save();

        $emailDelivery = $this->sendInvitationEmail($organization, $invitation, $plainToken);

        return $this->ok([
            'organization_id' => (int) $organization->id,
            'invitation' => $this->invitationPayload($invitation, true, $plainToken),
            'email_delivery' => $emailDelivery,
            'resend_policy' => [
                'cooldown_seconds' => self::RESEND_COOLDOWN_SECONDS,
                'rate_limit_max' => self::RESEND_RATE_LIMIT_MAX,
                'rate_limit_window_seconds' => self::RESEND_RATE_LIMIT_WINDOW_SECONDS,
                'rate_limit_remaining' => max(0, RateLimiter::remaining($this->resendQuotaKey($invitation), self::RESEND_RATE_LIMIT_MAX)),
            ],
        ], 'Invitation resent');
    }

    public function revokeInvitation(Request $request, int $invitationId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin can revoke invitation', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $invitation = OrganizationTeamInvitation::query()
            ->where('organization_id', (int) $organization->id)
            ->where('id', $invitationId)
            ->first();

        if (!$invitation instanceof OrganizationTeamInvitation) {
            return $this->fail('Invitation not found', ['code' => 'INVITATION_NOT_FOUND'], 404);
        }

        if ($invitation->status !== OrganizationTeamInvitation::STATUS_PENDING) {
            return $this->fail('Only pending invitation can be revoked', ['code' => 'INVITATION_NOT_PENDING'], 422);
        }

        $invitation->forceFill([
            'status' => OrganizationTeamInvitation::STATUS_REVOKED,
        ])->save();

        return $this->ok([
            'organization_id' => (int) $organization->id,
            'invitation' => $this->invitationPayload($invitation, false),
        ], 'Invitation revoked');
    }

    public function acceptInvitation(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $validated = $request->validate([
            'token' => ['required', 'string', 'min:16', 'max:255'],
        ]);

        $tokenHash = hash('sha256', (string) $validated['token']);

        $invitation = OrganizationTeamInvitation::query()
            ->where('token_hash', $tokenHash)
            ->first();

        if (!$invitation instanceof OrganizationTeamInvitation) {
            return $this->fail('Invitation token invalid', ['code' => 'INVITATION_INVALID'], 404);
        }

        if ($invitation->status !== OrganizationTeamInvitation::STATUS_PENDING) {
            return $this->fail('Invitation token is no longer active', ['code' => 'INVITATION_NOT_ACTIVE'], 422);
        }

        if ($invitation->expires_at && $invitation->expires_at->isPast()) {
            $invitation->forceFill([
                'status' => OrganizationTeamInvitation::STATUS_EXPIRED,
            ])->save();

            return $this->fail('Invitation has expired', ['code' => 'INVITATION_EXPIRED'], 422);
        }

        if (strtolower((string) $user->email) !== strtolower((string) $invitation->email)) {
            return $this->fail('Invitation email does not match current account', ['code' => 'INVITATION_EMAIL_MISMATCH'], 403);
        }

        $organization = Organization::query()->find((int) $invitation->organization_id);
        if (!$organization instanceof Organization) {
            return $this->fail('Organization not found', ['code' => 'ORG_NOT_FOUND'], 404);
        }

        if ($this->belongsToDifferentOrganization($user, (int) $organization->id)) {
            return $this->fail(
                'Akun ini sudah terhubung ke organisasi lain dan tidak boleh menerima invitation tambahan.',
                ['code' => 'USER_HAS_OTHER_ORGANIZATION'],
                409
            );
        }

        $alreadyMember = $organization->users()->where('users.id', (int) $user->id)->exists();
        if (!$alreadyMember) {
            $organization->users()->attach((int) $user->id, ['role' => (string) $invitation->role]);
        }

        $invitation->forceFill([
            'status' => OrganizationTeamInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_by_user_id' => (int) $user->id,
        ])->save();

        if ((int) ($user->current_organization_id ?? 0) <= 0) {
            $user->forceFill([
                'current_organization_id' => (int) $organization->id,
            ])->save();
        }

        $member = $organization->users()->where('users.id', (int) $user->id)->first();

        return $this->ok([
            'organization' => [
                'id' => (int) $organization->id,
                'name' => (string) $organization->name,
                'slug' => (string) $organization->slug,
            ],
            'member' => $member ? $this->memberPayload($member, (string) ($member->pivot->role ?? 'member')) : null,
            'invitation' => $this->invitationPayload($invitation, false),
        ], 'Invitation accepted');
    }

    private function resolveCurrentOrganizationContext(User $user): array
    {
        $orgId = (int) ($user->current_organization_id ?? 0);
        if ($orgId <= 0) {
            return [null, null, $this->fail('No active organization selected', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403)];
        }

        if ((string) ($user->role ?? '') === 'admin') {
            $organization = Organization::query()->find($orgId);
            if (!$organization instanceof Organization) {
                return [null, null, $this->fail('Current organization not found', ['code' => 'ORG_NOT_FOUND'], 404)];
            }

            return [$organization, 'owner', null];
        }

        $organization = $user->organizations()
            ->where('organizations.id', $orgId)
            ->first();

        if (!$organization instanceof Organization) {
            return [null, null, $this->fail('Current organization not found in your access list', ['code' => 'ORG_NOT_ACCESSIBLE'], 404)];
        }

        $requesterRole = (string) ($organization->pivot->role ?? 'member');

        return [$organization, $requesterRole, null];
    }

    private function belongsToDifferentOrganization(User $user, int $organizationId): bool
    {
        if ((string) ($user->role ?? '') === 'admin') {
            return false;
        }

        return $user->organizations()
            ->where('organizations.id', '!=', $organizationId)
            ->exists();
    }

    private function memberPayload(User $member, string $role): array
    {
        return [
            'id' => (int) $member->id,
            'name' => (string) $member->name,
            'email' => (string) $member->email,
            'role' => $role,
            'joined_at' => $member->pivot?->created_at,
        ];
    }

    private function invitationPayload(OrganizationTeamInvitation $invitation, bool $includeToken = false, ?string $plainToken = null): array
    {
        $payload = [
            'id' => (int) $invitation->id,
            'organization_id' => (int) $invitation->organization_id,
            'email' => (string) $invitation->email,
            'role' => (string) $invitation->role,
            'status' => (string) $invitation->status,
            'expires_at' => $invitation->expires_at,
            'accepted_at' => $invitation->accepted_at,
            'accepted_by_user_id' => $invitation->accepted_by_user_id ? (int) $invitation->accepted_by_user_id : null,
            'invited_by_user_id' => (int) $invitation->invited_by_user_id,
            'created_at' => $invitation->created_at,
            'updated_at' => $invitation->updated_at,
        ];

        if ($includeToken && $plainToken !== null) {
            $payload['token'] = $plainToken;
            $payload['accept_path'] = '/api/v1/hellom/organizations/current/team/invitations/accept';
        }

        return $payload;
    }

    private function sendInvitationEmail(Organization $organization, OrganizationTeamInvitation $invitation, string $plainToken): array
    {
        $appBase = trim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000/hellom')));
        $registerUrl = rtrim($appBase, '/') . '/register?inviteToken=' . urlencode($plainToken);

        return $this->platformMailService->sendTo($invitation->email, new OrganizationTeamInvitationMail(
            organizationName: (string) $organization->name,
            role: (string) $invitation->role,
            token: $plainToken,
            registerUrl: $registerUrl,
            expiresAt: $invitation->expires_at,
        ));
    }

    private function resendCooldownKey(OrganizationTeamInvitation $invitation): string
    {
        return 'hellom:team-invite:resend:cooldown:' . $invitation->id;
    }

    private function resendQuotaKey(OrganizationTeamInvitation $invitation): string
    {
        return 'hellom:team-invite:resend:quota:' . $invitation->id;
    }

    private function checkResendRateGuard(OrganizationTeamInvitation $invitation): ?array
    {
        $cooldownKey = $this->resendCooldownKey($invitation);
        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            return [
                'code' => 'INVITATION_RESEND_COOLDOWN',
                'retry_after_seconds' => RateLimiter::availableIn($cooldownKey),
            ];
        }

        $quotaKey = $this->resendQuotaKey($invitation);
        if (RateLimiter::tooManyAttempts($quotaKey, self::RESEND_RATE_LIMIT_MAX)) {
            return [
                'code' => 'INVITATION_RESEND_RATE_LIMIT',
                'retry_after_seconds' => RateLimiter::availableIn($quotaKey),
            ];
        }

        return null;
    }

    private function consumeResendRateGuard(OrganizationTeamInvitation $invitation): void
    {
        RateLimiter::hit($this->resendCooldownKey($invitation), self::RESEND_COOLDOWN_SECONDS);
        RateLimiter::hit($this->resendQuotaKey($invitation), self::RESEND_RATE_LIMIT_WINDOW_SECONDS);
    }
}
