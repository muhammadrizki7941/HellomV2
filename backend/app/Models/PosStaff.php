<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\Pos\PosRoles;

class PosStaff extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const ROLE_OUTLET_ADMIN = PosRoles::LEGACY_OUTLET_ADMIN;

    public const ROLE_CASHIER = 'cashier';

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        $tenantSlugs = Outlet::query()
            ->where('organization_id', $organizationId)
            ->pluck('tenant_slug')
            ->filter()
            ->push((string) Organization::query()->whereKey($organizationId)->value('pos_tenant_slug'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $query->where(function (Builder $staff) use ($organizationId, $tenantSlugs): void {
            $staff->where('organization_id', $organizationId);
            if ($tenantSlugs !== []) {
                $staff->orWhere(fn (Builder $legacy) => $legacy->whereNull('organization_id')->whereIn('tenant_id', $tenantSlugs));
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'outlet_id',
        'linked_user_id',
        'created_by_user_id',
        'name',
        'email',
        'phone',
        'role',
        'employment_status',
        'permissions',
        'hourly_rate',
        'joined_at',
        'notes',
        'attendance_qr_token',
        'attendance_qr_token_rotated_at',
        'last_activity_at',
    ];

    protected $casts = [
        'permissions' => 'array',
        'hourly_rate' => 'integer',
        'joined_at' => 'date',
        'attendance_qr_token_rotated_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'pos_staff_outlets')
            ->withTimestamps();
    }

    /**
     * Canonical target role while retaining the legacy stored value for compatibility.
     */
    public function businessRole(): string
    {
        return PosRoles::staffRole((string) $this->role);
    }

    /** @return list<int> */
    public function assignedOutletIds(): array
    {
        $outlets = $this->outlets()
            ->where('outlets.is_active', true)
            ->where(function ($query): void {
                if ($this->organization_id !== null) {
                    $query->where('outlets.organization_id', (int) $this->organization_id);
                } else {
                    $query->where('outlets.tenant_slug', (string) $this->tenant_id);
                }
            });
        $outletIds = $outlets
            ->pluck('outlets.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($outletIds !== []) {
            return $outletIds;
        }

        if ($this->outlet_id) {
            $legacyOutletId = Outlet::query()
                ->where('is_active', true)
                ->whereKey((int) $this->outlet_id)
                ->where(function ($query): void {
                    if ($this->organization_id !== null) {
                        $query->where('organization_id', (int) $this->organization_id);
                    } else {
                        $query->where('tenant_slug', (string) $this->tenant_id);
                    }
                })
                ->value('id');

            return $legacyOutletId ? [(int) $legacyOutletId] : [];
        }

        $legacyOutletId = Outlet::query()
            ->where('is_active', true)
            ->where('tenant_slug', (string) $this->tenant_id)
            ->value('id');

        return $legacyOutletId ? [(int) $legacyOutletId] : [];
    }

    /**
     * Bind a freshly-joined user account to its invited PosStaff record. Called
     * right after a cashier accepts an invitation / registers, so the user can
     * be recognised as POS staff and locked to this staff's outlet.
     */
    public static function linkInvitedUser(?int $posStaffId, int $userId): void
    {
        if (!$posStaffId || $userId <= 0) {
            return;
        }

        self::query()
            ->whereKey($posStaffId)
            ->whereNull('linked_user_id')
            ->update(['linked_user_id' => $userId]);
    }

    /**
     * The single active outlet this staff is bound to. Prefers the explicit
     * outlet_id and falls back to the tenant_slug scoping key.
     */
    public function resolveBoundOutlet(?int $requestedOutletId = null): ?Outlet
    {
        $assignedOutletIds = $this->assignedOutletIds();
        if ($requestedOutletId !== null && !in_array($requestedOutletId, $assignedOutletIds, true)) {
            return null;
        }

        $outletId = $requestedOutletId
            ?? (in_array((int) $this->outlet_id, $assignedOutletIds, true) ? (int) $this->outlet_id : ($assignedOutletIds[0] ?? null));

        if ($outletId !== null) {
            return Outlet::query()
                ->where('is_active', true)
                ->where(function ($query): void {
                    if ($this->organization_id !== null) {
                        $query->where('organization_id', (int) $this->organization_id);
                    } else {
                        $query->where('tenant_slug', (string) $this->tenant_id);
                    }
                })
                ->whereKey($outletId)
                ->first();
        }

        return Outlet::query()
            ->where('tenant_slug', (string) $this->tenant_id)
            ->where('is_active', true)
            ->first();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(PosStaffShift::class, 'staff_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(PosStaffAttendance::class, 'staff_id');
    }

    public function cashLogs(): HasMany
    {
        return $this->hasMany(PosStaffCashLog::class, 'staff_id');
    }
}
