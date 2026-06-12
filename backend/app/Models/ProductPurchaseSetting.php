<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPurchaseSetting extends Model
{
    use HasFactory;

    protected $table = 'product_purchase_settings';

    public const SERVICE_DINE_IN = 'dine_in';
    public const SERVICE_TAKE_AWAY = 'take_away';
    public const SERVICE_DELIVERY = 'delivery';
    public const SERVICE_PRE_ORDER = 'pre_order';

    public const TIMING_IMMEDIATE = 'immediate';
    public const TIMING_SCHEDULED = 'scheduled';
    public const TIMING_RESERVATION = 'reservation';

    protected $fillable = [
        'organization_id',
        'service_type',
        'enabled',
        'name',
        'description',
        'order_timing',
        'lead_time_minutes',
        'available_days',
        'start_time',
        'end_time',
        'require_payment_first',
        'require_table',
        'require_reservation',
        'max_order_per_day',
        'min_order_amount',
        'max_order_amount',
        'sort_order',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'lead_time_minutes' => 'integer',
            'available_days' => 'array',
            'require_payment_first' => 'boolean',
            'require_table' => 'boolean',
            'require_reservation' => 'boolean',
            'max_order_per_day' => 'integer',
            'min_order_amount' => 'integer',
            'max_order_amount' => 'integer',
            'sort_order' => 'integer',
            'is_default' => 'boolean',
            'start_time' => 'datetime:H:i:s',
            'end_time' => 'datetime:H:i:s',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function getServiceTypes(): array
    {
        return [
            self::SERVICE_DINE_IN => 'Dine In',
            self::SERVICE_TAKE_AWAY => 'Take Away',
            self::SERVICE_DELIVERY => 'Delivery',
            self::SERVICE_PRE_ORDER => 'Pre Order',
        ];
    }

    public static function getTimings(): array
    {
        return [
            self::TIMING_IMMEDIATE => 'Immediate (Sekarang)',
            self::TIMING_SCHEDULED => 'Scheduled (Jadwal)',
            self::TIMING_RESERVATION => 'Reservation (Reservasi)',
        ];
    }

    public function isAvailableNow(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Check available days
        $availableDays = $this->available_days ?? [];
        if (!empty($availableDays)) {
            $dayMap = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
            $currentDay = $dayMap[now()->dayOfWeek];
            if (!in_array($currentDay, $availableDays, true)) {
                return false;
            }
        }

        // Check time window
        if ($this->start_time || $this->end_time) {
            $now = now()->time;
            if ($this->start_time && $now < $this->start_time) {
                return false;
            }
            if ($this->end_time && $now > $this->end_time) {
                return false;
            }
        }

        return true;
    }

    public function canPreorder(): bool
    {
        return $this->order_timing !== self::TIMING_IMMEDIATE;
    }

    public function getNextAvailableTime(): ?\Carbon\Carbon
    {
        if ($this->order_timing === self::TIMING_IMMEDIATE) {
            return now();
        }

        $leadTime = $this->lead_time_minutes ?? 0;
        return now()->addMinutes($leadTime);
    }
}