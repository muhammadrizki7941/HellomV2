<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosStaff extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'linked_user_id',
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
    public function resolveBoundOutlet(): ?Outlet
    {
        if ($this->outlet_id) {
            $byId = Outlet::query()
                ->where('organization_id', (int) $this->organization_id)
                ->where('id', (int) $this->outlet_id)
                ->where('is_active', true)
                ->first();
            if ($byId instanceof Outlet) {
                return $byId;
            }
        }

        return Outlet::query()
            ->where('organization_id', (int) $this->organization_id)
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
