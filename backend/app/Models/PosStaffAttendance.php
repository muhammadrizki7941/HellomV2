<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosStaffAttendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'staff_id',
        'shift_id',
        'attendance_date',
        'status',
        'late_minutes',
        'check_in_at',
        'check_out_at',
        'check_in_method',
        'check_out_method',
        'location_label',
        'latitude',
        'longitude',
        'check_in_location_label',
        'check_in_latitude',
        'check_in_longitude',
        'check_in_scanned_by_user_id',
        'check_out_location_label',
        'check_out_latitude',
        'check_out_longitude',
        'check_out_scanned_by_user_id',
        'notes',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'check_in_latitude' => 'decimal:7',
        'check_in_longitude' => 'decimal:7',
        'check_out_latitude' => 'decimal:7',
        'check_out_longitude' => 'decimal:7',
        'late_minutes' => 'integer',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'staff_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosStaffShift::class, 'shift_id');
    }
}
