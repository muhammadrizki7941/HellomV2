<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Mail\OrganizationTeamInvitationMail;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationTeamInvitation;
use App\Models\Outlet;
use App\Models\PosStaff;
use App\Support\Pos\ActivityActions;
use App\Support\Pos\PosAccess;
use App\Support\Pos\PosPermissions;
use App\Models\PosStaffAttendance;
use App\Models\PosStaffCashLog;
use App\Models\PosStaffShift;
use App\Models\User;
use App\Services\Hellom\PlatformMailService;
use App\Services\Realtime\RealtimeClient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PosStaffController extends BasePosController
{
    public function myProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $org = $this->getOrg($request);
        if (! $user instanceof User || ! $org instanceof Organization) {
            return $this->error('Profil POS tidak ditemukan.', 'POS_PROFILE_NOT_FOUND', null, 404);
        }

        $staff = PosStaff::query()
            ->forOrganization((int) $org->id)
            ->where('linked_user_id', (int) $user->id)
            ->first();

        if (! $staff && $request->attributes->get('posStaff') instanceof PosStaff) {
            $boundStaff = $request->attributes->get('posStaff');
            if ((int) $boundStaff->linked_user_id === (int) $user->id) {
                $staff = $boundStaff;
            }
        }
        $outletNames = $staff
            ? Outlet::query()->whereIn('id', $staff->assignedOutletIds())->pluck('name')->values()
            : collect();

        return $this->success([
            'user' => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'phone' => $user->phone,
            ],
            'organization' => [
                'name' => (string) $org->name,
            ],
            'staff' => $staff ? [
                'id' => (int) $staff->id,
                'name' => (string) $staff->name,
                'role' => $staff->businessRole(),
                'status' => (string) $staff->employment_status,
                'outlets' => $outletNames,
                'attendance_qr' => $this->serializeAttendanceQr($staff),
            ] : null,
        ], 'Profil POS');
    }

    public function updateMyProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $org = $this->getOrg($request);
        if (! $user instanceof User || ! $org instanceof Organization) {
            return $this->error('Profil POS tidak ditemukan.', 'POS_PROFILE_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\\+?[0-9]{8,15}$/'],
        ]);
        $email = array_key_exists('email', $validated) ? strtolower((string) $validated['email']) : $user->email;
        $phone = array_key_exists('phone', $validated) ? $validated['phone'] : $user->phone;
        $identifiers = array_values(array_filter([$email, $phone, $user->username]));
        $identifierTaken = $identifiers !== [] && User::query()
            ->where('id', '!=', (int) $user->id)
            ->where(function ($query) use ($identifiers): void {
                foreach ($identifiers as $identifier) {
                    $query->orWhere('email', $identifier)
                        ->orWhere('username', $identifier)
                        ->orWhere('phone', $identifier);
                }
            })
            ->exists();
        if ($identifierTaken) {
            return $this->error('Email atau nomor HP sudah digunakan akun lain.', 'POS_PROFILE_IDENTIFIER_TAKEN', null, 409);
        }

        $staff = PosStaff::query()
            ->forOrganization((int) $org->id)
            ->where('linked_user_id', (int) $user->id)
            ->first();

        DB::transaction(function () use ($user, $staff, $validated, $email, $phone): void {
            $oldEmail = strtolower((string) $user->email);
            $user->forceFill([
                'name' => trim($validated['name']),
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'email_verified_at' => $email && $email !== $oldEmail ? null : $user->email_verified_at,
            ])->save();

            if ($staff) {
                $staff->forceFill([
                    'name' => trim($validated['name']),
                    'email' => $email ?: null,
                    'phone' => $phone ?: null,
                ])->save();
            }
        });

        return $this->myProfile($request);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', PosStaff::class);

        $org = $this->getOrg($request);
        $tenantSlug = $this->getActiveTenantSlug($request, $org);
        $staffActor = $request->attributes->get('posStaff');
        $isOrgAdmin = $this->isOrgOwner($request, $org);
        $filters = $request->validate([
            'role' => 'nullable|string|in:admin,cashier',
            'status' => 'nullable|string|in:active,inactive,on_leave',
            'outlet_id' => 'nullable|integer',
        ]);
        $visibleOutletIds = $staffActor instanceof PosStaff
            ? $staffActor->assignedOutletIds()
            : Outlet::query()->where('organization_id', $org->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (isset($filters['outlet_id']) && !in_array((int) $filters['outlet_id'], $visibleOutletIds, true)) {
            return $this->error('Outlet tidak tersedia pada akses akun ini.', 'POS_OUTLET_NOT_ASSIGNED', null, 403);
        }
        $tenantSlugs = Outlet::query()
            ->where('organization_id', $org->id)
            ->whereIn('id', $visibleOutletIds)
            ->pluck('tenant_slug')
            ->filter()
            ->push((string) $org->pos_tenant_slug)
            ->unique()
            ->values()
            ->all();
        $today = now()->startOfDay();
        $endOfToday = now()->endOfDay();

        $staffQuery = PosStaff::query()
            ->with(['linkedUser:id,name,email,username,phone,last_login_at', 'createdBy:id,name'])
            ->where(function ($query) use ($org, $tenantSlugs) {
                $query->where('organization_id', $org->id)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('organization_id')->whereIn('tenant_id', $tenantSlugs));
            });
        if ($staffActor instanceof PosStaff) {
            $staffQuery->where(function ($query) use ($visibleOutletIds) {
                $query->whereHas('outlets', fn ($outlets) => $outlets->whereIn('outlets.id', $visibleOutletIds))
                    ->orWhereIn('outlet_id', $visibleOutletIds);
            })->where(function ($visibleStaff) use ($staffActor) {
                $visibleStaff->where('role', PosStaff::ROLE_CASHIER)
                    ->orWhere('id', (int) $staffActor->id);
            });
        } elseif (isset($filters['outlet_id'])) {
            $staffQuery->where(function ($query) use ($filters) {
                $query->whereHas('outlets', fn ($outlets) => $outlets->where('outlets.id', (int) $filters['outlet_id']))
                    ->orWhere('outlet_id', (int) $filters['outlet_id']);
            });
        }
        if (isset($filters['role'])) {
            $staffQuery->where('role', $filters['role']);
        }
        if (isset($filters['status'])) {
            $staffQuery->where('employment_status', $filters['status']);
        }
        $staff = $staffQuery
            ->orderByRaw("FIELD(role, 'admin', 'cashier')")
            ->orderBy('name')
            ->get();

        $staffIds = $staff->pluck('id');
        $linkedUserIds = $staff->pluck('linked_user_id')->filter()->values();

        $shifts = PosStaffShift::query()
            ->whereIn('staff_id', $staffIds)
            ->whereBetween('start_at', [now()->copy()->subDays(1)->startOfDay(), now()->copy()->addDays(7)->endOfDay()])
            ->orderBy('start_at')
            ->get()
            ->groupBy('staff_id');

        $todayAttendances = PosStaffAttendance::query()
            ->whereIn('staff_id', $staffIds)
            ->whereDate('attendance_date', $today)
            ->get()
            ->keyBy('staff_id');

        $recentAttendances = PosStaffAttendance::query()
            ->whereIn('staff_id', $staffIds)
            ->whereBetween('attendance_date', [now()->copy()->subDays(14)->toDateString(), now()->toDateString()])
            ->orderByDesc('attendance_date')
            ->get();

        $openCashLogs = PosStaffCashLog::query()
            ->whereIn('staff_id', $staffIds)
            ->where('status', 'open')
            ->get()
            ->keyBy('staff_id');

        $recentCashLogs = PosStaffCashLog::query()
            ->whereIn('staff_id', $staffIds)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        $performanceRows = $linkedUserIds->isEmpty()
            ? collect()
            : Order::withoutGlobalScope('tenant')
                ->selectRaw('user_id, COUNT(*) as total_transactions, COALESCE(SUM(final_amount), 0) as total_sales')
                ->whereIn('tenant_id', $tenantSlugs)
                ->whereIn('outlet_id', $visibleOutletIds)
                ->whereIn('user_id', $linkedUserIds)
                ->where('payment_status', 'paid')
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

        $attendanceSummaries = $recentAttendances
            ->groupBy('staff_id')
            ->map(function (Collection $items) {
                $workMinutes = $items->sum(function (PosStaffAttendance $attendance) {
                    if (!$attendance->check_in_at || !$attendance->check_out_at) {
                        return 0;
                    }

                    return $attendance->check_out_at->diffInMinutes($attendance->check_in_at);
                });

                return [
                    'work_minutes' => $workMinutes,
                    'attendance_days' => $items->whereIn('status', ['present', 'late'])->count(),
                    'late_count' => $items->where('status', 'late')->count(),
                    'leave_count' => $items->where('status', 'leave')->count(),
                ];
            });

        $staffItems = $staff->map(function (PosStaff $member) use ($shifts, $todayAttendances, $openCashLogs, $performanceRows, $attendanceSummaries, $isOrgAdmin) {
            $this->ensureAttendanceQrToken($member);

            return $this->serializeStaff($member, $shifts->get($member->id, collect()), $todayAttendances->get($member->id), $openCashLogs->get($member->id), $performanceRows->get($member->linked_user_id), $attendanceSummaries->get($member->id), $isOrgAdmin);
        })->values();

        $scheduledTodayCount = $staffItems->filter(fn (array $member) => !empty($member['today_shift']))->count();
        $checkedInCount = $staffItems->filter(fn (array $member) => ($member['today_attendance']['checked_in'] ?? false) === true)->count();
        $openCashCount = $staffItems->filter(fn (array $member) => ($member['cash_session']['status'] ?? null) === 'open')->count();
        $attendanceRate = $staffItems->count() > 0
            ? round(($checkedInCount / max($scheduledTodayCount, 1)) * 100)
            : 0;

        $topStaff = collect($staffItems)
            ->sortByDesc(fn (array $member) => ($member['performance']['total_sales'] ?? 0) + (($member['performance']['total_transactions'] ?? 0) * 1000))
            ->take(5)
            ->values();

        $myStaff = $staff->first(function (PosStaff $member) use ($request) {
            $user = $request->user();
            if (!$user instanceof User) {
                return false;
            }

            return $member->linked_user_id === $user->id || (!empty($member->email) && strcasecmp((string) $member->email, (string) $user->email) === 0);
        });

        $notifications = $this->buildNotifications($staffItems, $today, $endOfToday);

        return $this->success([
            'summary' => [
                'total_staff' => $staffItems->count(),
                'active_staff' => collect($staffItems)->where('employment_status', 'active')->count(),
                'checked_in_now' => $checkedInCount,
                'scheduled_today' => $scheduledTodayCount,
                'open_cash_sessions' => $openCashCount,
                'attendance_rate' => $attendanceRate,
            ],
            'staff' => $staffItems,
            'top_staff' => $isOrgAdmin ? $topStaff : [],
            'notifications' => $isOrgAdmin ? $notifications : [],
            'today' => [
                'date' => $today->toDateString(),
                'shifts' => PosStaffShift::query()
                    ->with('staff:id,name,role')
                    ->whereIn('staff_id', $staffIds)
                    ->whereBetween('start_at', [$today, $endOfToday])
                    ->orderBy('start_at')
                    ->get()
                    ->map(fn (PosStaffShift $shift) => $this->serializeShift($shift)),
                'recent_attendances' => $isOrgAdmin ? $recentAttendances
                    ->take(8)
                    ->map(fn (PosStaffAttendance $attendance) => $this->serializeAttendance($attendance, $staff->firstWhere('id', $attendance->staff_id))) : [],
                'recent_cash_logs' => $isOrgAdmin
                    ? $recentCashLogs->map(fn (PosStaffCashLog $cashLog) => $this->serializeCashLog($cashLog, $staff->firstWhere('id', $cashLog->staff_id)))
                    : [],
            ],
            'current_user_staff_id' => $myStaff?->id,
            'meta' => [
                'roles' => $staffActor instanceof PosStaff ? ['cashier'] : ['admin', 'cashier'],
                'permissions' => PosPermissions::catalog(),
            ],
        ], 'Staff management loaded');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', PosStaff::class);

        $org = $this->getOrg($request);
        $tenantSlug = $this->getActiveTenantSlug($request, $org);
        $outletId = $request->attributes->get('posOutletId');
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'nullable|email|max:120',
            'phone' => 'nullable|string|max:30',
            'username' => ['nullable', 'string', 'min:3', 'max:50', 'alpha_dash'],
            'create_login' => 'nullable|boolean',
            'password' => 'nullable|string|min:8|max:128',
            'role' => 'required|string|in:admin,cashier',
            'outlet_ids' => 'nullable|array|min:1',
            'outlet_ids.*' => 'required|integer|distinct',
            'employment_status' => 'nullable|string|in:active,inactive,on_leave',
            'permissions' => 'nullable|array',
            'linked_user_id' => 'nullable|integer|exists:users,id',
            'hourly_rate' => 'nullable|integer|min:0',
            'joined_at' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $staffActor = $request->attributes->get('posStaff');
        if ($staffActor instanceof PosStaff && $validated['role'] !== PosStaff::ROLE_CASHIER) {
            return $this->error('Admin Outlet hanya dapat membuat akun Kasir.', 'STAFF_ROLE_FORBIDDEN', null, 403);
        }

        $outletIds = array_values(array_unique(array_map('intval', $validated['outlet_ids'] ?? [(int) $outletId])));
        $invalidOutletResponse = $this->validateStaffOutletAssignments($org, $outletIds, $staffActor);
        if ($invalidOutletResponse) {
            return $invalidOutletResponse;
        }
        $primaryOutlet = Outlet::query()->where('organization_id', $org->id)->whereKey($outletIds[0])->firstOrFail();
        $tenantSlug = (string) ($primaryOutlet->tenant_slug ?: $tenantSlug);

        $createLogin = filter_var($validated['create_login'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || filled($validated['username'] ?? null);
        $email = filled($validated['email'] ?? null) ? mb_strtolower(trim((string) $validated['email'])) : null;
        $username = filled($validated['username'] ?? null) ? mb_strtolower(trim((string) $validated['username'])) : null;
        $phone = filled($validated['phone'] ?? null) ? preg_replace('/[\s()-]/', '', (string) $validated['phone']) : null;
        $temporaryPassword = null;
        $linkedUserId = null;

        if ($createLogin) {
            if ($email === null && $username === null && $phone === null) {
                return $this->error('Isi email, username, atau nomor HP untuk membuat akun login.', 'STAFF_LOGIN_IDENTIFIER_REQUIRED', null, 422);
            }

            $identifiers = array_values(array_filter([$email, $username, $phone]));
            $existingUser = User::query()->where(function ($query) use ($identifiers): void {
                foreach ($identifiers as $identifier) {
                    $query->orWhere('email', $identifier)
                        ->orWhere('username', $identifier)
                        ->orWhere('phone', $identifier);
                }
            })->exists();
            if ($existingUser) {
                return $this->error('Email, username, atau nomor HP sudah dipakai akun lain.', 'STAFF_LOGIN_IDENTIFIER_TAKEN', null, 409);
            }
        } else {
            $linkedUserId = $this->resolveLinkedUserId($org->id, $validated['linked_user_id'] ?? null, $email);
            if ($linkedUserId === false) {
                return $this->error('User terhubung tidak termasuk tim organisasi ini', 'STAFF_USER_INVALID', null, 422);
            }
        }

        if ($staffActor instanceof PosStaff && $linkedUserId !== null) {
            return $this->error('Akun yang sudah ada tidak dapat ditautkan oleh Admin Outlet.', 'STAFF_LINK_FORBIDDEN', null, 403);
        }

        if ($linkedUserId !== null) {
            $membershipRole = DB::table('organization_user')
                ->where('organization_id', $org->id)
                ->where('user_id', $linkedUserId)
                ->value('role');
            if (in_array((string) $membershipRole, ['owner', 'admin'], true)) {
                return $this->error('Akun Admin Utama tidak dapat ditautkan sebagai akun staf POS.', 'STAFF_LINK_FORBIDDEN', null, 403);
            }
            if (PosStaff::query()->where('linked_user_id', $linkedUserId)->exists()) {
                return $this->error('Akun ini sudah tertaut ke data staf POS.', 'STAFF_ALREADY_LINKED', null, 409);
            }
        }

        $staff = DB::transaction(function () use ($request, $org, $tenantSlug, $outletIds, $validated, $createLogin, $email, $username, $phone, &$linkedUserId, &$temporaryPassword): PosStaff {
            if ($createLogin) {
                $temporaryPassword = $validated['password'] ?? Str::password(20);
                $user = User::query()->create([
                    'name' => trim($validated['name']),
                    'email' => $email,
                    'username' => $username,
                    'phone' => $phone,
                    'password' => Hash::make($temporaryPassword),
                    'must_change_password' => true,
                    'role' => 'cashier',
                    'current_organization_id' => $org->id,
                ]);
                DB::table('organization_user')->insert([
                    'organization_id' => (int) $org->id,
                    'user_id' => (int) $user->id,
                    'role' => 'cashier',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $linkedUserId = (int) $user->id;
            }

            $staff = PosStaff::query()->create([
                'tenant_id' => $tenantSlug,
                'outlet_id' => $outletIds[0],
                'organization_id' => $org->id,
                'linked_user_id' => $linkedUserId,
                'created_by_user_id' => $request->user()?->id,
                'name' => trim($validated['name']),
                'email' => $email,
                'phone' => $phone,
                'role' => $validated['role'],
                'employment_status' => $validated['employment_status'] ?? 'active',
                'permissions' => $this->normalizePermissions($validated['role'], $validated['permissions'] ?? null),
                'hourly_rate' => $validated['hourly_rate'] ?? 0,
                'joined_at' => $validated['joined_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'attendance_qr_token' => $this->generateAttendanceQrToken(),
                'attendance_qr_token_rotated_at' => now(),
                'last_activity_at' => now(),
            ]);
            $staff->outlets()->sync($outletIds);

            return $staff;
        });

        $this->recordActivity($request, ActivityActions::STAFF_CREATED, [
            'target_type' => 'staff',
            'target_id' => $staff->id,
            'target_label' => $staff->name,
            'after' => $this->accessSnapshot($staff),
        ]);

        return $this->success([
            'staff' => $this->serializeStaff(
                $staff->fresh(['linkedUser', 'createdBy']),
                collect(),
                null,
                null,
                null,
                null,
                $this->isOrgOwner($request, $org)
            ),
            'credentials' => $temporaryPassword === null ? null : [
                'identifier' => $username ?? $phone ?? $email,
                'password' => $temporaryPassword,
                'must_change_password' => true,
            ],
        ], 'Staff created', 201);
    }

    /**
     * Invite a staff member to get a login (email + set password) scoped to this
     * staff's outlet. On accept/register they become an org member with the
     * `cashier` pivot role and are linked back to this PosStaff record.
     */
    public function inviteLogin(Request $request, int $staffId): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org instanceof Organization) {
            return $this->error('Organisasi tidak ditemukan', 'NO_ORGANIZATION', null, 403);
        }

        $staff = $this->findStaffForManagement($request, $org, $staffId);

        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $email = strtolower(trim((string) ($validated['email'] ?? $staff->email ?? '')));
        if ($email === '') {
            return $this->error('Email staff wajib diisi untuk mengundang login', 'STAFF_EMAIL_REQUIRED', null, 422);
        }

        if ($staff->linked_user_id) {
            return $this->error('Staff ini sudah tertaut ke akun login', 'STAFF_ALREADY_LINKED', null, 409);
        }

        // Keep the staff email in sync so future actions match the invited address.
        if ((string) $staff->email !== $email) {
            $staff->forceFill(['email' => $email])->save();
        }

        $existingUser = User::query()->where('email', $email)->first();
        if ($existingUser instanceof User) {
            $isMember = $org->users()->where('users.id', $existingUser->id)->exists();
            $membershipRole = DB::table('organization_user')
                ->where('organization_id', $org->id)
                ->where('user_id', $existingUser->id)
                ->value('role');
            if ($request->attributes->get('posStaff') instanceof PosStaff
                && in_array((string) $membershipRole, ['owner', 'admin'], true)) {
                return $this->error('Admin Outlet tidak dapat menautkan akun Admin Utama.', 'STAFF_LINK_FORBIDDEN', null, 403);
            }
            if ($request->attributes->get('posStaff') instanceof PosStaff
                && PosStaff::query()->where('organization_id', $org->id)->where('linked_user_id', $existingUser->id)->exists()) {
                return $this->error('Akun ini sudah tertaut ke data staff lain.', 'STAFF_ALREADY_LINKED', null, 409);
            }
            $belongsToOther = $existingUser->organizations()
                ->where('organizations.id', '!=', $org->id)
                ->exists();

            if (!$isMember && $belongsToOther) {
                return $this->error('Akun email ini sudah terhubung ke organisasi lain', 'USER_HAS_OTHER_ORGANIZATION', null, 409);
            }

            if ($isMember) {
                // Already part of the org — link immediately, no email needed.
                PosStaff::linkInvitedUser($staff->id, (int) $existingUser->id);

                return $this->success([
                    'linked' => true,
                    'staff' => $this->serializeStaff(
                        $staff->fresh(['linkedUser', 'createdBy']),
                        collect(),
                        null,
                        null,
                        null,
                        null,
                        $this->isOrgOwner($request, $org)
                    ),
                ], 'Akun sudah menjadi anggota organisasi dan langsung ditautkan ke staff');
            }
        }

        $expiresInDays = (int) ($validated['expires_in_days'] ?? 7);
        $plainToken = Str::random(48);

        $invitation = OrganizationTeamInvitation::query()
            ->where('organization_id', (int) $org->id)
            ->where('email', $email)
            ->where('status', OrganizationTeamInvitation::STATUS_PENDING)
            ->latest('id')
            ->first();

        $attributes = [
            'role' => 'cashier',
            'pos_staff_id' => (int) $staff->id,
            'token_hash' => hash('sha256', $plainToken),
            'invited_by_user_id' => (int) $request->user()->id,
            'status' => OrganizationTeamInvitation::STATUS_PENDING,
            'expires_at' => now()->addDays($expiresInDays),
        ];

        if ($invitation instanceof OrganizationTeamInvitation) {
            $invitation->forceFill($attributes)->save();
        } else {
            $invitation = OrganizationTeamInvitation::query()->create(array_merge($attributes, [
                'organization_id' => (int) $org->id,
                'email' => $email,
            ]));
        }

        $delivery = $this->sendStaffInvitationEmail($org, $invitation, $plainToken);

        return $this->success([
            'linked' => false,
            'invitation' => [
                'id' => (int) $invitation->id,
                'email' => (string) $invitation->email,
                'role' => (string) $invitation->role,
                'status' => (string) $invitation->status,
                'expires_at' => $invitation->expires_at,
            ],
            'email_delivery' => $delivery,
            'staff' => $this->serializeStaff(
                $staff->fresh(['linkedUser', 'createdBy']),
                collect(),
                null,
                null,
                null,
                null,
                $this->isOrgOwner($request, $org)
            ),
        ], 'Undangan login dikirim ke email staff', 201);
    }

    private function sendStaffInvitationEmail(Organization $organization, OrganizationTeamInvitation $invitation, string $plainToken): array
    {
        $appBase = trim((string) config('app.frontend_url'));
        // Invitation page: log in (existing account) or create one, then straight into POS.
        $registerUrl = rtrim($appBase, '/') . '/invitation/accept?token=' . urlencode($plainToken);

        return app(PlatformMailService::class)->sendTo($invitation->email, new OrganizationTeamInvitationMail(
            organizationName: (string) $organization->name,
            role: (string) $invitation->role,
            token: $plainToken,
            registerUrl: $registerUrl,
            expiresAt: $invitation->expires_at,
        ));
    }

    public function update(Request $request, int $staffId): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org instanceof Organization) {
            return $this->error('Organisasi tidak ditemukan', 'NO_ORGANIZATION', null, 403);
        }
        $staff = $this->findStaffForManagement($request, $org, $staffId);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'nullable|email|max:120',
            'phone' => 'nullable|string|max:30',
            'role' => 'required|string|in:admin,cashier',
            'outlet_ids' => 'nullable|array|min:1',
            'outlet_ids.*' => 'required|integer|distinct',
            'employment_status' => 'nullable|string|in:active,inactive,on_leave',
            'permissions' => 'nullable|array',
            'linked_user_id' => 'nullable|integer|exists:users,id',
            'hourly_rate' => 'nullable|integer|min:0',
            'joined_at' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);
        $staffActor = $request->attributes->get('posStaff');
        if ($staffActor instanceof PosStaff) {
            if ($validated['role'] !== PosStaff::ROLE_CASHIER) {
                return $this->error('Admin Outlet hanya dapat mengelola akun Kasir.', 'STAFF_ROLE_FORBIDDEN', null, 403);
            }
            if ($request->exists('linked_user_id') && (int) ($validated['linked_user_id'] ?? 0) !== (int) $staff->linked_user_id) {
                return $this->error('Admin Outlet tidak dapat mengganti akun login staff.', 'STAFF_LINK_FORBIDDEN', null, 403);
            }
        }
        $beforeAccess = $this->accessSnapshot($staff);
        $outletIds = null;
        if (array_key_exists('outlet_ids', $validated)) {
            $outletIds = array_values(array_unique(array_map('intval', $validated['outlet_ids'])));
            $invalidOutletResponse = $this->validateStaffOutletAssignments($org, $outletIds, $staffActor);
            if ($invalidOutletResponse) {
                return $invalidOutletResponse;
            }
        }

        $linkedUserId = $this->resolveLinkedUserId($org->id, $validated['linked_user_id'] ?? null, $validated['email'] ?? null);

        if ($linkedUserId === false) {
            return $this->error('User terhubung tidak termasuk tim organisasi ini', 'STAFF_USER_INVALID', null, 422);
        }
        if ($staffActor instanceof PosStaff && (int) ($linkedUserId ?? $staff->linked_user_id ?? 0) !== (int) ($staff->linked_user_id ?? 0)) {
            return $this->error('Admin Outlet tidak dapat mengganti atau menautkan akun login staff.', 'STAFF_LINK_FORBIDDEN', null, 403);
        }
        // The edit form does not send linked_user_id: keep the invited login instead of unlinking
        // the cashier (who would lose POS access) whenever the staff email differs from theirs.
        if ($linkedUserId === null && !$request->exists('linked_user_id')) {
            $linkedUserId = $staff->linked_user_id;
        }

        $before = [
            'name' => $staff->name,
            'email' => $staff->email,
            'phone' => $staff->phone,
            'role' => $staff->role,
            'employment_status' => $staff->employment_status,
            'outlet_ids' => $staff->assignedOutletIds(),
            'permissions' => PosPermissions::forStaff($staff),
        ];
        $oldLinkedUserId = (int) ($staff->linked_user_id ?? 0);
        $oldAccess = $this->accessSnapshot($staff);
        $nextStatus = $validated['employment_status'] ?? $staff->employment_status;
        $nextRole = $validated['role'];
        $nextOutletIds = $outletIds ?? $staff->assignedOutletIds();
        $nextAccess = [
            'role' => $nextRole,
            'business_role' => $nextRole === PosStaff::ROLE_OUTLET_ADMIN ? 'outlet_admin' : 'cashier',
            'employment_status' => $nextStatus,
            'linked_user_id' => $linkedUserId ? (int) $linkedUserId : null,
            'permissions' => PosPermissions::normalize($nextRole, $validated['permissions'] ?? $staff->permissions),
            'outlet_ids' => $nextOutletIds,
        ];
        $identityAccessChanged = $oldAccess['role'] !== $nextAccess['role']
            || $oldAccess['employment_status'] !== $nextAccess['employment_status']
            || $oldAccess['linked_user_id'] !== $nextAccess['linked_user_id']
            || $oldAccess['outlet_ids'] !== $nextAccess['outlet_ids'];

        DB::transaction(function () use ($staff, $outletIds, $linkedUserId, $validated, $nextStatus): void {
            if ($outletIds !== null) {
                $staff->outlets()->sync($outletIds);
                $staff->forceFill(['outlet_id' => $outletIds[0]])->save();
            }
            $staff->update([
                'linked_user_id' => $linkedUserId,
                'name' => trim($validated['name']),
                'email' => array_key_exists('email', $validated) ? (filled($validated['email']) ? mb_strtolower(trim((string) $validated['email'])) : null) : $staff->email,
                'phone' => array_key_exists('phone', $validated) ? (filled($validated['phone']) ? preg_replace('/[\s()-]/', '', (string) $validated['phone']) : null) : $staff->phone,
                'role' => $validated['role'],
                'employment_status' => $nextStatus,
                'permissions' => $this->normalizePermissions($validated['role'], $validated['permissions'] ?? $staff->permissions),
                'hourly_rate' => $validated['hourly_rate'] ?? $staff->hourly_rate,
                'joined_at' => $validated['joined_at'] ?? $staff->joined_at,
                'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $staff->notes,
            ]);
        });
        $staff->refresh();
        if ($identityAccessChanged || $oldLinkedUserId !== (int) ($staff->linked_user_id ?? 0)) {
            $this->revokeStaffUserAccess($oldLinkedUserId ?: null);
            if ($staff->linked_user_id && (int) $staff->linked_user_id !== $oldLinkedUserId) {
                $this->revokeStaffUserAccess((int) $staff->linked_user_id);
            }
        }
        $after = [
            'name' => $staff->name,
            'email' => $staff->email,
            'phone' => $staff->phone,
            'role' => $staff->role,
            'employment_status' => $staff->employment_status,
            'outlet_ids' => $staff->assignedOutletIds(),
            'permissions' => PosPermissions::forStaff($staff),
        ];
        $this->recordActivity($request, ActivityActions::STAFF_PROFILE_UPDATED, [
            'target_type' => 'staff',
            'target_id' => $staff->id,
            'target_label' => $staff->name,
            'before' => $before,
            'after' => $after,
        ]);

        return $this->success([
            'staff' => $this->serializeStaff(
                $staff->fresh(['linkedUser', 'createdBy']),
                collect(),
                null,
                null,
                null,
                null,
                $this->isOrgOwner($request, $org)
            ),
        ], 'Staff updated');
    }

    public function showAttendanceQr(Request $request, int $staffId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        $staff = PosStaff::query()
            ->where('tenant_id', $tenantSlug)
            ->findOrFail($staffId);

        $this->ensureAttendanceQrToken($staff);

        return $this->success([
            'staff_id' => $staff->id,
            'staff_name' => $staff->name,
            'attendance_qr' => $this->serializeAttendanceQr($staff, false),
        ], 'Attendance QR loaded');
    }

    public function regenerateAttendanceQr(Request $request, int $staffId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        $staff = PosStaff::query()
            ->where('tenant_id', $tenantSlug)
            ->findOrFail($staffId);

        $staff->forceFill([
            'attendance_qr_token' => $this->generateAttendanceQrToken(),
            'attendance_qr_token_rotated_at' => now(),
        ])->save();

        return $this->success([
            'staff_id' => $staff->id,
            'staff_name' => $staff->name,
            'attendance_qr' => $this->serializeAttendanceQr($staff->fresh()),
        ], 'Attendance QR regenerated');
    }

    public function destroy(Request $request, int $staffId): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org instanceof Organization) {
            return $this->error('Organisasi tidak ditemukan', 'NO_ORGANIZATION', null, 403);
        }
        $staff = $this->findStaffForManagement($request, $org, $staffId);

        $before = $this->accessSnapshot($staff);
        $staff->forceFill(['employment_status' => 'inactive'])->save();
        $this->revokeStaffUserAccess($staff->linked_user_id ? (int) $staff->linked_user_id : null);
        $this->recordActivity($request, ActivityActions::STAFF_DEACTIVATED, [
            'target_type' => 'staff',
            'target_id' => $staff->id,
            'target_label' => $staff->name,
            'before' => $before,
            'after' => $this->accessSnapshot($staff),
        ]);

        return $this->success(null, 'Akun staf berhasil dinonaktifkan');
    }

    public function resetPassword(Request $request, int $staffId): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org instanceof Organization) {
            return $this->error('Organisasi tidak ditemukan', 'NO_ORGANIZATION', null, 403);
        }

        $staff = $this->findStaffForManagement($request, $org, $staffId);
        $user = $staff->linkedUser;
        if (!$user instanceof User) {
            return $this->error('Staf belum memiliki akun login. Buat akun login dari form staf terlebih dahulu.', 'STAFF_LOGIN_NOT_LINKED', null, 409);
        }

        $password = Str::password(20);
        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
        ])->save();
        $this->revokeStaffUserAccess((int) $user->id);
        $this->recordActivity($request, ActivityActions::STAFF_PASSWORD_RESET, [
            'target_type' => 'staff',
            'target_id' => $staff->id,
            'target_label' => $staff->name,
            'after' => ['must_change_password' => true],
        ]);

        return $this->success([
            'staff_id' => $staff->id,
            'credentials' => [
                'identifier' => $user->username ?: $user->email ?: $user->phone,
                'password' => $password,
                'must_change_password' => true,
            ],
        ], 'Kata sandi sementara dibuat. Tampilkan sekali dan berikan langsung kepada staf.');
    }

    public function storeShift(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $validated = $request->validate([
            'staff_id' => 'required|integer',
            'title' => 'nullable|string|max:100',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'reminder_minutes' => 'nullable|integer|min:0|max:180',
            'notes' => 'nullable|string|max:1000',
        ]);

        $staff = PosStaff::query()
            ->where('tenant_id', $tenantSlug)
            ->findOrFail($validated['staff_id']);

        $shift = PosStaffShift::create([
            'tenant_id' => $tenantSlug,
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'assigned_by_user_id' => $request->user()?->id,
            'title' => trim((string) ($validated['title'] ?? 'Shift')),
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'],
            'status' => Carbon::parse($validated['start_at'])->isPast() ? 'in_progress' : 'scheduled',
            'reminder_minutes' => $validated['reminder_minutes'] ?? 30,
            'notes' => $validated['notes'] ?? null,
        ]);

        return $this->success([
            'shift' => $this->serializeShift($shift->fresh('staff')),
        ], 'Shift created', 201);
    }

    public function updateShift(Request $request, int $shiftId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');

        $shift = PosStaffShift::query()
            ->where('tenant_id', $tenantSlug)
            ->findOrFail($shiftId);

        $validated = $request->validate([
            'title' => 'nullable|string|max:100',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'status' => 'nullable|string|in:scheduled,in_progress,completed,missed,cancelled',
            'reminder_minutes' => 'nullable|integer|min:0|max:180',
            'notes' => 'nullable|string|max:1000',
        ]);

        $shift->update([
            'title' => trim((string) ($validated['title'] ?? $shift->title)),
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'],
            'status' => $validated['status'] ?? $shift->status,
            'reminder_minutes' => $validated['reminder_minutes'] ?? $shift->reminder_minutes,
            'notes' => $validated['notes'] ?? null,
        ]);

        return $this->success([
            'shift' => $this->serializeShift($shift->fresh('staff')),
        ], 'Shift updated');
    }

    public function checkIn(Request $request, int $staffId): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $validated = $request->validate([
            'method' => 'nullable|string|in:manual,gps,qr',
            'location_label' => 'nullable|string|max:150',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'notes' => 'nullable|string|max:1000',
        ]);

        $staff = PosStaff::query()
            ->where('tenant_id', $tenantSlug)
            ->findOrFail($staffId);

        return $this->success([
            'attendance' => $this->performCheckIn($org->id, $tenantSlug, $staff, $validated, $request->user()?->id),
        ], 'Check-in saved');
    }

    public function checkOut(Request $request, int $staffId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        $validated = $request->validate([
            'method' => 'nullable|string|in:manual,gps,qr',
            'location_label' => 'nullable|string|max:150',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'notes' => 'nullable|string|max:1000',
        ]);

        $staff = PosStaff::query()
            ->where('tenant_id', $tenantSlug)
            ->findOrFail($staffId);

        return $this->success([
            'attendance' => $this->performCheckOut($tenantSlug, $staff, $validated, $request->user()?->id),
        ], 'Check-out saved');
    }

    public function scanAttendanceQr(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);
        $validated = $request->validate([
            'qr_content' => 'required|string|max:4000',
            'action' => 'required|string|in:check_in,check_out',
            'location_label' => 'nullable|string|max:150',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'notes' => 'nullable|string|max:1000',
        ]);

        $token = $this->extractAttendanceQrToken($validated['qr_content'], $tenantSlug);
        if (!$token) {
            return $this->error('QR staff tidak valid atau bukan milik tenant ini', 'STAFF_QR_INVALID', null, 422);
        }

        $staff = PosStaff::query()
            ->where('tenant_id', $tenantSlug)
            ->where('attendance_qr_token', $token)
            ->first();

        if (!$staff) {
            return $this->error('QR staff tidak ditemukan atau sudah diganti', 'STAFF_QR_NOT_FOUND', null, 404);
        }

        if ($staff->employment_status === 'inactive') {
            return $this->error('Staff nonaktif tidak bisa diproses melalui QR attendance', 'STAFF_INACTIVE', null, 422);
        }

        $attendance = $validated['action'] === 'check_in'
            ? $this->performCheckIn($org->id, $tenantSlug, $staff, $validated + ['method' => 'qr'], $request->user()?->id)
            : $this->performCheckOut($tenantSlug, $staff, $validated + ['method' => 'qr'], $request->user()?->id);

        return $this->success([
            'staff_id' => $staff->id,
            'staff_name' => $staff->name,
            'attendance' => $attendance,
        ], $validated['action'] === 'check_in' ? 'QR check-in saved' : 'QR check-out saved');
    }

    public function markLeave(Request $request, int $staffId): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $validated = $request->validate([
            'attendance_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $staff = PosStaff::query()
            ->where('tenant_id', $tenantSlug)
            ->findOrFail($staffId);

        $attendanceDate = Carbon::parse($validated['attendance_date'] ?? now()->toDateString())->toDateString();

        $attendance = PosStaffAttendance::updateOrCreate(
            [
                'tenant_id' => $tenantSlug,
                'staff_id' => $staff->id,
                'attendance_date' => $attendanceDate,
            ],
            [
                'organization_id' => $org->id,
                'status' => 'leave',
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return $this->success([
            'attendance' => $this->serializeAttendance($attendance, $staff),
        ], 'Leave marked');
    }

    public function openCash(Request $request, int $staffId): JsonResponse
    {
        if ($denied = $this->denyOtherStaff($request, $staffId)) {
            return $denied;
        }
        $org = $this->getOrg($request);
        $tenantSlug = (string) $request->attributes->get('posTenantSlug');
        $validated = $request->validate([
            'opening_cash' => 'required|integer|min:0',
            'shift_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:1000',
        ]);

        $staff = PosStaff::query()
            ->where('tenant_id', $tenantSlug)
            ->findOrFail($staffId);

        $existingOpen = PosStaffCashLog::query()
            ->where('tenant_id', $tenantSlug)
            ->where('staff_id', $staff->id)
            ->where('status', 'open')
            ->first();

        if ($existingOpen) {
            return $this->error('Masih ada kas shift yang belum ditutup', 'STAFF_CASH_STILL_OPEN', null, 422);
        }

        $cashLog = DB::transaction(function () use ($request, $tenantSlug, $org, $staff, $validated) {
            $cashLog = PosStaffCashLog::create([
                'tenant_id' => $tenantSlug,
                'organization_id' => $org->id,
                'staff_id' => $staff->id,
                'shift_id' => $validated['shift_id'] ?? null,
                'opening_cash' => $validated['opening_cash'],
                'started_at' => now(),
                'status' => 'open',
                'notes' => $validated['notes'] ?? null,
                'activity_log' => [
                    ['label' => 'Kas awal diinput', 'at' => now()->toIso8601String(), 'amount' => $validated['opening_cash']],
                ],
            ]);
            $this->recordActivity($request, ActivityActions::CASH_OPENED, ['target_type' => 'cash_session', 'target_id' => $cashLog->id,
                'target_label' => 'Kas ' . $staff->name, 'after' => ['staff_id' => (int) $staff->id, 'opening_cash' => (int) $validated['opening_cash']],
                'reason' => $validated['notes'] ?? null]);

            return $cashLog;
        });

        return $this->success([
            'cash_log' => $this->serializeCashLog($cashLog, $staff, $this->blindCash($request)),
        ], 'Cash shift opened', 201);
    }

    public function closeCash(Request $request, int $staffId): JsonResponse
    {
        if ($denied = $this->denyOtherStaff($request, $staffId)) {
            return $denied;
        }
        $tenantSlug = $request->attributes->get('posTenantSlug');
        $validated = $request->validate([
            'closing_cash' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $staff = PosStaff::query()
            ->where('tenant_id', $tenantSlug)
            ->findOrFail($staffId);

        $cashLog = PosStaffCashLog::query()
            ->where('tenant_id', $tenantSlug)
            ->where('staff_id', $staff->id)
            ->where('status', 'open')
            ->latest('started_at')
            ->firstOrFail();

        [$totalTransactions, $totalCashSales] = $this->calculateCashPerformance($tenantSlug, $staff, $cashLog->started_at, now());
        $expectedCash = $cashLog->opening_cash + $totalCashSales;
        $differenceCash = $validated['closing_cash'] - $expectedCash;
        $activityLog = $cashLog->activity_log ?? [];
        $activityLog[] = [
            'label' => 'Kas akhir dihitung',
            'at' => now()->toIso8601String(),
            'amount' => $validated['closing_cash'],
            'expected_cash' => $expectedCash,
            'difference_cash' => $differenceCash,
        ];

        DB::transaction(function () use ($request, $cashLog, $staff, $validated, $expectedCash, $differenceCash, $totalCashSales, $totalTransactions, $activityLog) {
            $cashLog->update([
                'closing_cash' => $validated['closing_cash'],
                'expected_cash' => $expectedCash,
                'difference_cash' => $differenceCash,
                'total_cash_sales' => $totalCashSales,
                'total_transactions' => $totalTransactions,
                'closed_at' => now(),
                'status' => 'closed',
                'notes' => $validated['notes'] ?? $cashLog->notes,
                'activity_log' => $activityLog,
            ]);
            $short = $differenceCash < 0 ? -$differenceCash : 0;
            $this->recordActivity($request, ActivityActions::CASH_CLOSED, [
                'target_type' => 'cash_session', 'target_id' => $cashLog->id, 'target_label' => 'Kas ' . $staff->name,
                'before' => ['opening_cash' => (int) $cashLog->opening_cash, 'started_at' => optional($cashLog->started_at)->toIso8601String()],
                'after' => ['staff_id' => (int) $staff->id, 'counted_cash' => (int) $validated['closing_cash'], 'expected_cash' => (int) $expectedCash,
                    'difference_cash' => (int) $differenceCash, 'cash_sales' => (int) $totalCashSales, 'transactions' => (int) $totalTransactions,
                    'blind' => $this->blindCash($request)],
                'reason' => $validated['notes'] ?? null,
                'severity' => $differenceCash === 0 ? 'info' : ($short >= \App\Services\Pos\Audit\AnomalyReport::CASH_SHORT_CRITICAL ? 'critical' : 'warning'),
            ]);
        });

        return $this->success([
            'cash_log' => $this->serializeCashLog($cashLog->fresh(), $staff, $this->blindCash($request)),
        ], 'Cash shift closed');
    }

    public function export(Request $request): StreamedResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        $type = $request->query('type', 'attendance');
        $month = $request->query('month', now()->format('Y-m'));
        $start = Carbon::parse($month . '-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $filename = sprintf('pos-staff-%s-%s.csv', $type, $start->format('Y-m'));

        return response()->streamDownload(function () use ($tenantSlug, $type, $start, $end) {
            $handle = fopen('php://output', 'wb');

            if ($type === 'cash') {
                fputcsv($handle, ['Staff', 'Mulai Shift', 'Tutup Shift', 'Kas Awal', 'Kas Akhir', 'Expected', 'Selisih', 'Transaksi', 'Penjualan Tunai', 'Status']);
                PosStaffCashLog::query()
                    ->with('staff:id,name')
                    ->where('tenant_id', $tenantSlug)
                    ->whereBetween('started_at', [$start, $end])
                    ->orderBy('started_at')
                    ->chunk(200, function ($logs) use ($handle) {
                        foreach ($logs as $log) {
                            fputcsv($handle, [
                                $log->staff?->name,
                                optional($log->started_at)->format('Y-m-d H:i'),
                                optional($log->closed_at)->format('Y-m-d H:i'),
                                $log->opening_cash,
                                $log->closing_cash,
                                $log->expected_cash,
                                $log->difference_cash,
                                $log->total_transactions,
                                $log->total_cash_sales,
                                $log->status,
                            ]);
                        }
                    });
            } elseif ($type === 'performance') {
                fputcsv($handle, ['Staff', 'Role', 'Total Transaksi', 'Total Penjualan', 'Jam Kerja', 'Telat', 'Izin']);
                $staff = PosStaff::query()
                    ->where('tenant_id', $tenantSlug)
                    ->get();
                foreach ($staff as $member) {
                    [$transactions, $sales] = $this->calculateSalesPerformance($tenantSlug, $member, $start, $end);
                    [$minutes, $lateCount, $leaveCount] = $this->calculateAttendancePerformance($tenantSlug, $member, $start, $end);
                    fputcsv($handle, [
                        $member->name,
                        $member->role,
                        $transactions,
                        $sales,
                        round($minutes / 60, 1),
                        $lateCount,
                        $leaveCount,
                    ]);
                }
            } else {
                fputcsv($handle, ['Tanggal', 'Staff', 'Role', 'Status', 'Check In', 'Metode Masuk', 'Lokasi Masuk', 'Check Out', 'Metode Keluar', 'Lokasi Keluar', 'Telat (menit)']);
                PosStaffAttendance::query()
                    ->with('staff:id,name,role')
                    ->where('tenant_id', $tenantSlug)
                    ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
                    ->orderBy('attendance_date')
                    ->chunk(200, function ($items) use ($handle) {
                        foreach ($items as $attendance) {
                            fputcsv($handle, [
                                optional($attendance->attendance_date)->format('Y-m-d'),
                                $attendance->staff?->name,
                                $attendance->staff?->role,
                                $attendance->status,
                                optional($attendance->check_in_at)->format('H:i'),
                                $attendance->check_in_method,
                                $attendance->check_in_location_label ?: $attendance->location_label,
                                optional($attendance->check_out_at)->format('H:i'),
                                $attendance->check_out_method,
                                $attendance->check_out_location_label,
                                $attendance->late_minutes,
                            ]);
                        }
                    });
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function serializeStaff(
        PosStaff $staff,
        Collection $shifts,
        ?PosStaffAttendance $todayAttendance,
        ?PosStaffCashLog $cashLog,
        mixed $performanceRow,
        ?array $attendanceSummary,
        bool $includeAdminDetails = false
    ): array {
        $todayShift = $shifts
            ->first(fn (PosStaffShift $shift) => $shift->start_at->isToday());

        $upcomingShift = $shifts
            ->first(fn (PosStaffShift $shift) => $shift->start_at->isFuture());

        $performance = [
            'total_transactions' => (int) ($performanceRow->total_transactions ?? 0),
            'total_sales' => (int) ($performanceRow->total_sales ?? 0),
            'work_minutes' => (int) ($attendanceSummary['work_minutes'] ?? 0),
            'late_count' => (int) ($attendanceSummary['late_count'] ?? 0),
            'leave_count' => (int) ($attendanceSummary['leave_count'] ?? 0),
            'attendance_days' => (int) ($attendanceSummary['attendance_days'] ?? 0),
        ];

        return [
            'id' => $staff->id,
            'name' => $staff->name,
            'email' => $staff->email,
            'phone' => $staff->phone,
            'role' => $this->normalizeRole($staff->role),
            'business_role' => $staff->businessRole(),
            'outlet_ids' => $staff->assignedOutletIds(),
            'employment_status' => $staff->employment_status,
            'permissions' => PosPermissions::forStaff($staff),
            'hourly_rate' => $staff->hourly_rate,
            'joined_at' => optional($staff->joined_at)->toDateString(),
            'notes' => $staff->notes,
            'linked_user_id' => $staff->linked_user_id,
            'linked_user_name' => $staff->linkedUser?->name,
            'last_activity_at' => optional($staff->last_activity_at)->toIso8601String(),
            'created_at' => optional($staff->created_at)->toIso8601String(),
            'created_by' => $includeAdminDetails ? [
                'id' => $staff->created_by_user_id,
                'name' => $staff->createdBy?->name,
            ] : null,
            'login_identifier' => $includeAdminDetails
                ? ($staff->linkedUser?->username ?: $staff->linkedUser?->email ?: $staff->linkedUser?->phone)
                : null,
            'last_login_at' => $includeAdminDetails
                ? optional($staff->linkedUser?->last_login_at)->toIso8601String()
                : null,
            'attendance_qr' => $this->serializeAttendanceQr($staff),
            'today_shift' => $todayShift ? $this->serializeShift($todayShift) : null,
            'upcoming_shift' => $upcomingShift ? $this->serializeShift($upcomingShift) : null,
            'today_attendance' => $todayAttendance ? $this->serializeAttendance($todayAttendance, $staff) : null,
            'cash_session' => $cashLog ? $this->serializeCashLog($cashLog, $staff) : null,
            'performance' => $performance,
        ];
    }

    private function serializeShift(PosStaffShift $shift): array
    {
        return [
            'id' => $shift->id,
            'staff_id' => $shift->staff_id,
            'staff_name' => $shift->staff?->name,
            'title' => $shift->title,
            'start_at' => optional($shift->start_at)->toIso8601String(),
            'end_at' => optional($shift->end_at)->toIso8601String(),
            'status' => $shift->status,
            'reminder_minutes' => $shift->reminder_minutes,
            'notes' => $shift->notes,
        ];
    }

    private function serializeAttendance(PosStaffAttendance $attendance, ?PosStaff $staff): array
    {
        return [
            'id' => $attendance->id,
            'staff_id' => $attendance->staff_id,
            'staff_name' => $staff?->name,
            'attendance_date' => optional($attendance->attendance_date)->toDateString(),
            'status' => $attendance->status,
            'late_minutes' => $attendance->late_minutes,
            'check_in_at' => optional($attendance->check_in_at)->toIso8601String(),
            'check_out_at' => optional($attendance->check_out_at)->toIso8601String(),
            'checked_in' => !is_null($attendance->check_in_at),
            'checked_out' => !is_null($attendance->check_out_at),
            'check_in_method' => $attendance->check_in_method,
            'check_out_method' => $attendance->check_out_method,
            'location_label' => $attendance->location_label,
            'latitude' => $attendance->latitude ? (float) $attendance->latitude : null,
            'longitude' => $attendance->longitude ? (float) $attendance->longitude : null,
            'check_in_location_label' => $attendance->check_in_location_label,
            'check_in_latitude' => $attendance->check_in_latitude ? (float) $attendance->check_in_latitude : null,
            'check_in_longitude' => $attendance->check_in_longitude ? (float) $attendance->check_in_longitude : null,
            'check_out_location_label' => $attendance->check_out_location_label,
            'check_out_latitude' => $attendance->check_out_latitude ? (float) $attendance->check_out_latitude : null,
            'check_out_longitude' => $attendance->check_out_longitude ? (float) $attendance->check_out_longitude : null,
            'notes' => $attendance->notes,
        ];
    }

    private function serializeAttendanceQr(PosStaff $staff, bool $includeSvg = true): array
    {
        $this->ensureAttendanceQrToken($staff);

        $payload = $this->buildAttendanceQrPayload($staff);

        return [
            'token_preview' => Str::upper(substr($staff->attendance_qr_token ?? '', 0, 10)),
            'rotated_at' => optional($staff->attendance_qr_token_rotated_at)->toIso8601String(),
            'payload' => $payload,
            'svg_data_uri' => $includeSvg
                ? 'data:image/svg+xml;base64,' . base64_encode(
                    QrCode::format('svg')
                        ->size(280)
                        ->margin(1)
                        ->generate(json_encode($payload, JSON_UNESCAPED_SLASHES))
                )
                : null,
        ];
    }

    /** $blind: hide what the system expects (cashiers count the drawer without seeing it). */
    private function serializeCashLog(PosStaffCashLog $cashLog, ?PosStaff $staff, bool $blind = false): array
    {
        return [
            'id' => $cashLog->id,
            'staff_id' => $cashLog->staff_id,
            'staff_name' => $staff?->name,
            'status' => $cashLog->status,
            'opening_cash' => $cashLog->opening_cash,
            'closing_cash' => $cashLog->closing_cash,
            'expected_cash' => $blind ? null : $cashLog->expected_cash,
            'difference_cash' => $blind ? null : $cashLog->difference_cash,
            'total_cash_sales' => $blind ? null : $cashLog->total_cash_sales,
            'total_transactions' => $cashLog->total_transactions,
            'started_at' => optional($cashLog->started_at)->toIso8601String(),
            'closed_at' => optional($cashLog->closed_at)->toIso8601String(),
            'notes' => $cashLog->notes,
        ];
    }

    private function buildNotifications(Collection $staffItems, Carbon $today, Carbon $endOfToday): array
    {
        $notifications = [];

        foreach ($staffItems as $member) {
            $todayShift = $member['today_shift'] ?? null;
            $attendance = $member['today_attendance'] ?? null;

            if ($todayShift) {
                $shiftStart = Carbon::parse($todayShift['start_at']);
                $shiftEnd = Carbon::parse($todayShift['end_at']);

                if (!$attendance && now()->betweenIncluded($shiftStart->copy()->subMinutes(30), $shiftStart)) {
                    $notifications[] = [
                        'type' => 'shift_reminder',
                        'staff_id' => $member['id'],
                        'message' => $member['name'] . ' akan mulai shift ' . $shiftStart->format('H:i'),
                    ];
                }

                if (!$attendance && now()->greaterThan($shiftStart)) {
                    $notifications[] = [
                        'type' => 'late_attendance',
                        'staff_id' => $member['id'],
                        'message' => $member['name'] . ' belum check-in untuk shift hari ini',
                    ];
                }

                if ($attendance && !($attendance['checked_out'] ?? false) && now()->betweenIncluded($shiftEnd->copy()->subMinutes(30), $endOfToday)) {
                    $notifications[] = [
                        'type' => 'shift_end',
                        'staff_id' => $member['id'],
                        'message' => 'Shift ' . $member['name'] . ' hampir selesai, ingat check-out dan tutup kas',
                    ];
                }
            }
        }

        return array_slice($notifications, 0, 8);
    }

    private function normalizePermissions(string $role, ?array $permissions): array
    {
        return PosPermissions::normalize($this->normalizeRole($role), $permissions);
    }

    /**
     * @param list<int> $outletIds
     */
    private function validateStaffOutletAssignments(Organization $org, array $outletIds, ?PosStaff $staffActor): ?JsonResponse
    {
        $validIds = Outlet::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->whereIn('id', $outletIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($outletIds === [] || count($validIds) !== count($outletIds)) {
            return $this->error('Pilih outlet aktif milik organisasi ini.', 'STAFF_OUTLET_INVALID', null, 422);
        }
        if ($staffActor instanceof PosStaff && array_diff($outletIds, $staffActor->assignedOutletIds()) !== []) {
            return $this->error('Admin Outlet hanya dapat menugaskan Kasir ke outlet yang dikelolanya.', 'STAFF_OUTLET_FORBIDDEN', null, 403);
        }

        return null;
    }

    private function findStaffForManagement(Request $request, Organization $org, int $staffId): PosStaff
    {
        $tenantSlugs = Outlet::query()
            ->where('organization_id', $org->id)
            ->pluck('tenant_slug')
            ->filter()
            ->push((string) $org->pos_tenant_slug)
            ->unique()
            ->values()
            ->all();
        $query = PosStaff::query()
            ->where(function ($scope) use ($org, $tenantSlugs) {
                $scope->where('organization_id', $org->id)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('organization_id')->whereIn('tenant_id', $tenantSlugs));
            })
            ->whereKey($staffId);
        $staffActor = $request->attributes->get('posStaff');
        if ($staffActor instanceof PosStaff) {
            $outletIds = $staffActor->assignedOutletIds();
            $query->where(function ($scope) use ($outletIds) {
                $scope->whereHas('outlets', fn ($outlets) => $outlets->whereIn('outlets.id', $outletIds))
                    ->orWhereIn('outlet_id', $outletIds);
            });
        }

        $staff = $query->firstOrFail();
        Gate::forUser($request->user())->authorize('update', $staff);
        if ($staffActor instanceof PosStaff && $staff->businessRole() !== 'cashier') {
            abort(403, 'Admin Outlet hanya dapat mengelola akun Kasir pada outlet yang ditugaskan.');
        }

        return $staff;
    }

    private function performCheckIn(int $organizationId, string $tenantSlug, PosStaff $staff, array $validated, ?int $scannedByUserId): array
    {
        $now = now();
        $shift = $this->findCurrentShift($tenantSlug, $staff->id, $now);
        $attendance = PosStaffAttendance::firstOrNew([
            'tenant_id' => $tenantSlug,
            'staff_id' => $staff->id,
            'attendance_date' => $now->toDateString(),
        ]);

        if ($attendance->check_in_at) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json($this->errorPayload('Staff ini sudah check-in hari ini', 'STAFF_ALREADY_CHECKED_IN'), 422)
            );
        }

        $lateMinutes = 0;
        $status = 'present';
        if ($shift && $now->greaterThan($shift->start_at)) {
            $lateMinutes = $shift->start_at->diffInMinutes($now);
            if ($lateMinutes > 0) {
                $status = 'late';
            }
            if ($shift->status === 'scheduled') {
                $shift->update(['status' => 'in_progress']);
            }
        }

        $attendance->fill([
            'organization_id' => $organizationId,
            'shift_id' => $shift?->id,
            'status' => $status,
            'late_minutes' => $lateMinutes,
            'check_in_at' => $now,
            'check_in_method' => $validated['method'] ?? 'manual',
            'location_label' => $validated['location_label'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'check_in_location_label' => $validated['location_label'] ?? null,
            'check_in_latitude' => $validated['latitude'] ?? null,
            'check_in_longitude' => $validated['longitude'] ?? null,
            'check_in_scanned_by_user_id' => ($validated['method'] ?? 'manual') === 'qr' ? $scannedByUserId : null,
            'notes' => $validated['notes'] ?? null,
        ]);
        $attendance->save();

        $staff->update(['last_activity_at' => $now]);

        return $this->serializeAttendance($attendance->fresh(), $staff);
    }

    private function performCheckOut(string $tenantSlug, PosStaff $staff, array $validated, ?int $scannedByUserId): array
    {
        $attendance = PosStaffAttendance::query()
            ->where('tenant_id', $tenantSlug)
            ->where('staff_id', $staff->id)
            ->whereDate('attendance_date', now())
            ->firstOrFail();

        if ($attendance->check_out_at) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json($this->errorPayload('Staff ini sudah check-out hari ini', 'STAFF_ALREADY_CHECKED_OUT'), 422)
            );
        }

        $attendance->update([
            'check_out_at' => now(),
            'check_out_method' => $validated['method'] ?? 'manual',
            'check_out_location_label' => $validated['location_label'] ?? null,
            'check_out_latitude' => $validated['latitude'] ?? null,
            'check_out_longitude' => $validated['longitude'] ?? null,
            'check_out_scanned_by_user_id' => ($validated['method'] ?? 'manual') === 'qr' ? $scannedByUserId : null,
            'notes' => $validated['notes'] ?? $attendance->notes,
        ]);

        if ($attendance->shift_id) {
            PosStaffShift::query()
                ->whereKey($attendance->shift_id)
                ->whereIn('status', ['scheduled', 'in_progress'])
                ->update(['status' => 'completed']);
        }

        $staff->update(['last_activity_at' => now()]);

        return $this->serializeAttendance($attendance->fresh(), $staff);
    }

    /**
     * GET /pos/me/cash: the signed-in staff's cash drawer at this outlet, for the cashier
     * screen. Open drawer → live cash sales and expected cash so far; plus the last closed one.
     */
    public function myCash(Request $request): JsonResponse
    {
        $tenantSlug = (string) $request->attributes->get('posTenantSlug');
        $staff = $request->attributes->get('posStaff');
        if (!$staff instanceof PosStaff) {
            // Owner/admin working the till with their own staff record at this outlet.
            $staff = PosStaff::query()->where('tenant_id', $tenantSlug)->where('linked_user_id', (int) $request->user()->id)
                ->where('employment_status', 'active')->first();
        }
        if (!$staff instanceof PosStaff) {
            return $this->success(['staff_id' => null, 'open' => null, 'last_closed' => null], 'Belum terdaftar sebagai staf outlet ini');
        }

        $open = PosStaffCashLog::query()->where('tenant_id', $tenantSlug)->where('staff_id', $staff->id)
            ->where('status', 'open')->latest('started_at')->first();
        $openPayload = null;
        $blind = $this->blindCash($request);
        if ($open) {
            [$transactions, $cashSales] = $this->calculateCashPerformance($tenantSlug, $staff, $open->started_at, now());
            $openPayload = $this->serializeCashLog($open, $staff, $blind) + [
                'live_transactions' => $transactions,
                'live_cash_sales' => $blind ? null : $cashSales,
                'live_expected_cash' => $blind ? null : (int) $open->opening_cash + $cashSales,
            ];
        }
        $lastClosed = PosStaffCashLog::query()->where('tenant_id', $tenantSlug)->where('staff_id', $staff->id)
            ->where('status', 'closed')->latest('closed_at')->first();

        return $this->success([
            'staff_id' => (int) $staff->id,
            'open' => $openPayload,
            'last_closed' => $lastClosed ? $this->serializeCashLog($lastClosed, $staff, $blind) : null,
            'blind' => $blind,
        ], 'Kas saya');
    }

    /**
     * POST /pos/cash/no-sale {reason}: the drawer was opened without a sale (change for a note,
     * checking cash…). There is no drawer hardware in the web POS: this records it, with the
     * cashier's count for today, so the owner sees unusual drawer openings in Log Audit.
     */
    public function noSale(Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:120'], ['reason.required' => 'Pilih atau tulis alasan membuka laci.']);
        $tenantSlug = (string) $request->attributes->get('posTenantSlug');
        $staff = $request->attributes->get('posStaff');
        if (!$staff instanceof PosStaff) {
            $staff = PosStaff::query()->where('tenant_id', $tenantSlug)->where('linked_user_id', (int) $request->user()->id)
                ->where('employment_status', 'active')->first();
        }
        $open = $staff instanceof PosStaff
            ? PosStaffCashLog::query()->where('tenant_id', $tenantSlug)->where('staff_id', $staff->id)->where('status', 'open')->latest('started_at')->first()
            : null;
        $organizationId = $this->posOrganizationId($request);

        $count = DB::transaction(function () use ($request, $validated, $staff, $open, $organizationId) {
            $count = \App\Models\PosActivityLog::query()->where('organization_id', $organizationId)
                ->where('action', ActivityActions::DRAWER_NO_SALE)->where('actor_user_id', (int) $request->user()->id)
                ->where('occurred_at', '>=', now()->startOfDay())->count() + 1;
            $this->recordActivity($request, ActivityActions::DRAWER_NO_SALE, [
                'target_type' => 'cash_session', 'target_id' => $open?->id,
                'target_label' => $open ? 'Kas ' . ($staff?->name ?? '') : 'Laci kasir',
                'after' => ['cash_session_id' => $open?->id, 'count_today' => $count],
                'reason' => $validated['reason'],
                'severity' => $count >= 3 ? 'critical' : 'warning',
            ]);

            return $count;
        });

        return $this->success(['count_today' => $count], 'Tercatat: laci dibuka tanpa transaksi');
    }

    /** GET /pos/me/access: the signed-in user's POS permissions (cashier menu refresh). */
    public function myAccess(Request $request): JsonResponse
    {
        return $this->success(PosAccess::forUser($request->user()), 'Akses POS');
    }

    /** Cashiers (posStaff attribute) may only act on their own staff record. */
    /** Blind closing: POS staff (cashiers / POS admins) never see expected cash or the difference. */
    private function blindCash(Request $request): bool
    {
        return $request->attributes->get('posStaff') instanceof PosStaff;
    }

    /** @return array<string, mixed> */
    private function accessSnapshot(PosStaff $staff): array
    {
        return [
            'role' => (string) $staff->role,
            'business_role' => $staff->businessRole(),
            'employment_status' => (string) $staff->employment_status,
            'linked_user_id' => $staff->linked_user_id ? (int) $staff->linked_user_id : null,
            'permissions' => PosPermissions::forStaff($staff),
            'outlet_ids' => $staff->assignedOutletIds(),
        ];
    }

    private function revokeStaffUserAccess(?int $userId): void
    {
        if (! $userId) {
            return;
        }

        User::query()->whereKey($userId)->first()?->apiTokens()->delete();
        app(RealtimeClient::class)->emitToRoom('user_'.$userId, 'auth.revoked', [
            'reason' => 'pos_access_changed',
        ]);
    }

    private function denyOtherStaff(Request $request, int $staffId): ?JsonResponse
    {
        $me = $request->attributes->get('posStaff');
        if ($me instanceof PosStaff && (int) $me->id !== $staffId) {
            return $this->error('Kamu hanya bisa membuka/menutup kas milikmu sendiri', 'POS_PERMISSION_DENIED', null, 403);
        }

        return null;
    }

    private function normalizeRole(string $role): string
    {
        return $role === 'owner' ? 'admin' : $role;
    }

    private function resolveLinkedUserId(int $organizationId, ?int $linkedUserId, ?string $email): int|bool|null
    {
        if ($linkedUserId) {
            $exists = DB::table('organization_user')
                ->where('organization_id', $organizationId)
                ->where('user_id', $linkedUserId)
                ->exists();

            return $exists ? $linkedUserId : false;
        }

        if (!$email) {
            return null;
        }

        return DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $organizationId)
            ->whereRaw('LOWER(users.email) = ?', [mb_strtolower($email)])
            ->value('users.id');
    }

    private function findCurrentShift(string $tenantSlug, int $staffId, Carbon $now): ?PosStaffShift
    {
        return PosStaffShift::query()
            ->where('tenant_id', $tenantSlug)
            ->where('staff_id', $staffId)
            ->whereDate('start_at', $now->toDateString())
            ->orderBy('start_at')
            ->first();
    }

    private function ensureAttendanceQrToken(PosStaff $staff): void
    {
        if (!filled($staff->attendance_qr_token)) {
            $staff->forceFill([
                'attendance_qr_token' => $this->generateAttendanceQrToken(),
                'attendance_qr_token_rotated_at' => now(),
            ])->save();
        }
    }

    private function generateAttendanceQrToken(): string
    {
        do {
            $token = Str::lower(Str::random(48));
        } while (PosStaff::query()->where('attendance_qr_token', $token)->exists());

        return $token;
    }

    private function buildAttendanceQrPayload(PosStaff $staff): array
    {
        return [
            'type' => 'pos_staff_attendance',
            'version' => 1,
            'tenant' => $staff->tenant_id,
            'staff_id' => $staff->id,
            'staff_name' => $staff->name,
            'token' => $staff->attendance_qr_token,
        ];
    }

    private function extractAttendanceQrToken(string $qrContent, string $tenantSlug): ?string
    {
        $decoded = json_decode($qrContent, true);
        if (is_array($decoded)) {
            if (($decoded['type'] ?? null) !== 'pos_staff_attendance') {
                return null;
            }

            if (($decoded['tenant'] ?? null) !== $tenantSlug) {
                return null;
            }

            return filled($decoded['token'] ?? null) ? (string) $decoded['token'] : null;
        }

        return filled($qrContent) ? trim($qrContent) : null;
    }

    private function errorPayload(string $message, string $code): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => null,
            'error' => [
                'code' => $code,
                'details' => null,
            ],
        ];
    }

    private function calculateCashPerformance(string $tenantSlug, PosStaff $staff, Carbon $startedAt, Carbon $closedAt): array
    {
        if (!$staff->linked_user_id) {
            return [0, 0];
        }

        $summary = Order::withoutGlobalScope('tenant')
            ->selectRaw('COUNT(*) as total_transactions, COALESCE(SUM(final_amount), 0) as total_sales')
            ->where('tenant_id', $tenantSlug)
            ->where('user_id', $staff->linked_user_id)
            ->where('payment_status', 'paid')
            ->where('payment_method', 'cash')
            ->whereBetween('paid_at', [$startedAt, $closedAt])
            ->first();

        return [
            (int) ($summary->total_transactions ?? 0),
            (int) ($summary->total_sales ?? 0),
        ];
    }

    private function calculateSalesPerformance(string $tenantSlug, PosStaff $staff, Carbon $start, Carbon $end): array
    {
        if (!$staff->linked_user_id) {
            return [0, 0];
        }

        $summary = Order::withoutGlobalScope('tenant')
            ->selectRaw('COUNT(*) as total_transactions, COALESCE(SUM(final_amount), 0) as total_sales')
            ->where('tenant_id', $tenantSlug)
            ->where('user_id', $staff->linked_user_id)
            ->where('payment_status', 'paid')
            ->whereBetween('paid_at', [$start, $end])
            ->first();

        return [
            (int) ($summary->total_transactions ?? 0),
            (int) ($summary->total_sales ?? 0),
        ];
    }

    private function calculateAttendancePerformance(string $tenantSlug, PosStaff $staff, Carbon $start, Carbon $end): array
    {
        $items = PosStaffAttendance::query()
            ->where('tenant_id', $tenantSlug)
            ->where('staff_id', $staff->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $workMinutes = $items->sum(function (PosStaffAttendance $attendance) {
            if (!$attendance->check_in_at || !$attendance->check_out_at) {
                return 0;
            }

            return $attendance->check_out_at->diffInMinutes($attendance->check_in_at);
        });

        return [
            $workMinutes,
            $items->where('status', 'late')->count(),
            $items->where('status', 'leave')->count(),
        ];
    }
}
