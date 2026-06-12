<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\Order;
use App\Models\PosStaff;
use App\Models\PosStaffAttendance;
use App\Models\PosStaffCashLog;
use App\Models\PosStaffShift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PosStaffController extends BasePosController
{
    private const PERMISSION_KEYS = [
        'transactions',
        'reports',
        'products',
        'orders',
        'cash_control',
    ];

    public function index(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);
        $today = now()->startOfDay();
        $endOfToday = now()->endOfDay();

        $staff = PosStaff::query()
            ->with('linkedUser:id,name,email')
            ->where('tenant_id', $tenantSlug)
            ->orderByRaw("FIELD(role, 'admin', 'cashier')")
            ->orderBy('name')
            ->get();

        $staffIds = $staff->pluck('id');
        $linkedUserIds = $staff->pluck('linked_user_id')->filter()->values();

        $shifts = PosStaffShift::query()
            ->where('tenant_id', $tenantSlug)
            ->whereBetween('start_at', [now()->copy()->subDays(1)->startOfDay(), now()->copy()->addDays(7)->endOfDay()])
            ->orderBy('start_at')
            ->get()
            ->groupBy('staff_id');

        $todayAttendances = PosStaffAttendance::query()
            ->where('tenant_id', $tenantSlug)
            ->whereDate('attendance_date', $today)
            ->get()
            ->keyBy('staff_id');

        $recentAttendances = PosStaffAttendance::query()
            ->where('tenant_id', $tenantSlug)
            ->whereBetween('attendance_date', [now()->copy()->subDays(14)->toDateString(), now()->toDateString()])
            ->orderByDesc('attendance_date')
            ->get();

        $openCashLogs = PosStaffCashLog::query()
            ->where('tenant_id', $tenantSlug)
            ->where('status', 'open')
            ->get()
            ->keyBy('staff_id');

        $recentCashLogs = PosStaffCashLog::query()
            ->where('tenant_id', $tenantSlug)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        $performanceRows = $linkedUserIds->isEmpty()
            ? collect()
            : Order::withoutGlobalScope('tenant')
                ->selectRaw('user_id, COUNT(*) as total_transactions, COALESCE(SUM(final_amount), 0) as total_sales')
                ->where('tenant_id', $tenantSlug)
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

        $staffItems = $staff->map(function (PosStaff $member) use ($shifts, $todayAttendances, $openCashLogs, $performanceRows, $attendanceSummaries) {
            $this->ensureAttendanceQrToken($member);

            return $this->serializeStaff($member, $shifts->get($member->id, collect()), $todayAttendances->get($member->id), $openCashLogs->get($member->id), $performanceRows->get($member->linked_user_id), $attendanceSummaries->get($member->id));
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
            'top_staff' => $topStaff,
            'notifications' => $notifications,
            'today' => [
                'date' => $today->toDateString(),
                'shifts' => PosStaffShift::query()
                    ->with('staff:id,name,role')
                    ->where('tenant_id', $tenantSlug)
                    ->whereBetween('start_at', [$today, $endOfToday])
                    ->orderBy('start_at')
                    ->get()
                    ->map(fn (PosStaffShift $shift) => $this->serializeShift($shift)),
                'recent_attendances' => $recentAttendances
                    ->take(8)
                    ->map(fn (PosStaffAttendance $attendance) => $this->serializeAttendance($attendance, $staff->firstWhere('id', $attendance->staff_id))),
                'recent_cash_logs' => $recentCashLogs
                    ->map(fn (PosStaffCashLog $cashLog) => $this->serializeCashLog($cashLog, $staff->firstWhere('id', $cashLog->staff_id))),
            ],
            'current_user_staff_id' => $myStaff?->id,
            'meta' => [
                'roles' => ['admin', 'cashier'],
                'permissions' => self::PERMISSION_KEYS,
            ],
        ], 'Staff management loaded');
    }

    public function store(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'nullable|email|max:120',
            'phone' => 'nullable|string|max:30',
            'role' => 'required|string|in:admin,cashier',
            'employment_status' => 'nullable|string|in:active,inactive,on_leave',
            'permissions' => 'nullable|array',
            'linked_user_id' => 'nullable|integer|exists:users,id',
            'hourly_rate' => 'nullable|integer|min:0',
            'joined_at' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $linkedUserId = $this->resolveLinkedUserId($org->id, $validated['linked_user_id'] ?? null, $validated['email'] ?? null);

        if ($linkedUserId === false) {
            return $this->error('User terhubung tidak termasuk tim organisasi ini', 'STAFF_USER_INVALID', null, 422);
        }

        $staff = PosStaff::create([
            'tenant_id' => $tenantSlug,
            'organization_id' => $org->id,
            'linked_user_id' => $linkedUserId,
            'name' => trim($validated['name']),
            'email' => filled($validated['email'] ?? null) ? trim((string) $validated['email']) : null,
            'phone' => filled($validated['phone'] ?? null) ? trim((string) $validated['phone']) : null,
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

        return $this->success([
            'staff' => $this->serializeStaff($staff->fresh('linkedUser'), collect(), null, null, null, null),
        ], 'Staff created', 201);
    }

    public function update(Request $request, int $staffId): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);
        $staff = PosStaff::query()
            ->where('tenant_id', $tenantSlug)
            ->findOrFail($staffId);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'nullable|email|max:120',
            'phone' => 'nullable|string|max:30',
            'role' => 'required|string|in:admin,cashier',
            'employment_status' => 'nullable|string|in:active,inactive,on_leave',
            'permissions' => 'nullable|array',
            'linked_user_id' => 'nullable|integer|exists:users,id',
            'hourly_rate' => 'nullable|integer|min:0',
            'joined_at' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $linkedUserId = $this->resolveLinkedUserId($org->id, $validated['linked_user_id'] ?? null, $validated['email'] ?? null);

        if ($linkedUserId === false) {
            return $this->error('User terhubung tidak termasuk tim organisasi ini', 'STAFF_USER_INVALID', null, 422);
        }

        $staff->update([
            'linked_user_id' => $linkedUserId,
            'name' => trim($validated['name']),
            'email' => filled($validated['email'] ?? null) ? trim((string) $validated['email']) : null,
            'phone' => filled($validated['phone'] ?? null) ? trim((string) $validated['phone']) : null,
            'role' => $validated['role'],
            'employment_status' => $validated['employment_status'] ?? 'active',
            'permissions' => $this->normalizePermissions($validated['role'], $validated['permissions'] ?? null),
            'hourly_rate' => $validated['hourly_rate'] ?? 0,
            'joined_at' => $validated['joined_at'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return $this->success([
            'staff' => $this->serializeStaff($staff->fresh('linkedUser'), collect(), null, null, null, null),
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
        $tenantSlug = $request->attributes->get('posTenantSlug');
        $staff = PosStaff::query()
            ->where('tenant_id', $tenantSlug)
            ->findOrFail($staffId);

        $staff->delete();

        return $this->success(null, 'Staff deleted');
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
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);
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

        return $this->success([
            'cash_log' => $this->serializeCashLog($cashLog, $staff),
        ], 'Cash shift opened', 201);
    }

    public function closeCash(Request $request, int $staffId): JsonResponse
    {
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

        return $this->success([
            'cash_log' => $this->serializeCashLog($cashLog->fresh(), $staff),
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
        ?array $attendanceSummary
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
            'employment_status' => $staff->employment_status,
            'permissions' => $staff->permissions ?? $this->defaultPermissionsForRole($this->normalizeRole($staff->role)),
            'hourly_rate' => $staff->hourly_rate,
            'joined_at' => optional($staff->joined_at)->toDateString(),
            'notes' => $staff->notes,
            'linked_user_id' => $staff->linked_user_id,
            'linked_user_name' => $staff->linkedUser?->name,
            'last_activity_at' => optional($staff->last_activity_at)->toIso8601String(),
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

    private function serializeCashLog(PosStaffCashLog $cashLog, ?PosStaff $staff): array
    {
        return [
            'id' => $cashLog->id,
            'staff_id' => $cashLog->staff_id,
            'staff_name' => $staff?->name,
            'status' => $cashLog->status,
            'opening_cash' => $cashLog->opening_cash,
            'closing_cash' => $cashLog->closing_cash,
            'expected_cash' => $cashLog->expected_cash,
            'difference_cash' => $cashLog->difference_cash,
            'total_cash_sales' => $cashLog->total_cash_sales,
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
        $base = $this->defaultPermissionsForRole($role);
        if (!$permissions) {
            return $base;
        }

        foreach (self::PERMISSION_KEYS as $key) {
            if (array_key_exists($key, $permissions)) {
                $base[$key] = (bool) $permissions[$key];
            }
        }

        return $base;
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

    private function defaultPermissionsForRole(string $role): array
    {
        return match ($role) {
            'admin' => [
                'transactions' => true,
                'reports' => true,
                'products' => true,
                'orders' => true,
                'cash_control' => true,
            ],
            default => [
                'transactions' => true,
                'reports' => false,
                'products' => false,
                'orders' => true,
                'cash_control' => true,
            ],
        };
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
