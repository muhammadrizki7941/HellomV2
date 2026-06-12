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
