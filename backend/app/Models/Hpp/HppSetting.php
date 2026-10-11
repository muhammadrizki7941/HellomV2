<?php

namespace App\Models\Hpp;

use Illuminate\Database\Eloquent\Model;

/** Kalkulator HPP settings per organization (mode, business type, margin, rounding, price method). */
class HppSetting extends Model
{
    protected $table = 'hpp_settings';

    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'target_margin_pct' => 'float',
        'labor_rate_per_hour' => 'integer',
        'extra' => 'array',
        'onboarded_at' => 'datetime',
    ];

    public const MODES = ['quick', 'recipe', 'pro'];
    public const BUSINESS_TYPES = ['fnb', 'retail', 'service', 'production', 'digital'];
    public const ROUNDINGS = ['none', '100', '500', '1000', 'psych'];
    public const PRICE_METHODS = ['last', 'average', 'fifo'];

    public static function for(int $organizationId): self
    {
        return self::query()->firstOrCreate(['organization_id' => $organizationId]);
    }

    /** Values from `extra` (monthly estimates, overhead method, …) with defaults. */
    public function extraValue(string $key, mixed $default = null): mixed
    {
        return ((array) ($this->extra ?? []))[$key] ?? $default;
    }
}
