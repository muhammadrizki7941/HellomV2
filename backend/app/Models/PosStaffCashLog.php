<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosStaffCashLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'staff_id',
        'shift_id',
        'opening_cash',
        'closing_cash',
        'expected_cash',
        'difference_cash',
        'total_cash_sales',
        'total_transactions',
        'started_at',
        'closed_at',
        'status',
        'notes',
        'activity_log',
    ];

    protected $casts = [
        'opening_cash' => 'integer',
        'closing_cash' => 'integer',
        'expected_cash' => 'integer',
        'difference_cash' => 'integer',
        'total_cash_sales' => 'integer',
        'total_transactions' => 'integer',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
        'activity_log' => 'array',
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
