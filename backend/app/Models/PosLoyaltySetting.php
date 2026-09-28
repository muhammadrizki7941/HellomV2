<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class PosLoyaltySetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'enabled',
        'points_per_amount',
        'min_spend_amount',
        'max_points_per_order',
        'redeem_value_per_point',
        'min_redeem_points',
        'max_redeem_points_per_order',
        'points_expire_months',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'points_per_amount' => 'integer',
        'min_spend_amount' => 'integer',
        'max_points_per_order' => 'integer',
        'redeem_value_per_point' => 'integer',
        'min_redeem_points' => 'integer',
        'max_redeem_points_per_order' => 'integer',
        'points_expire_months' => 'integer',
    ];

    public static function defaults(string $tenantId): array
    {
        return [
            'tenant_id' => $tenantId,
            'enabled' => true,
            'points_per_amount' => 1000,
            'min_spend_amount' => 0,
            'max_points_per_order' => null,
        ];
    }

    public static function currentForTenant(string $tenantId): self
    {
        if (!Schema::hasTable('pos_loyalty_settings')) {
            return new static(static::readFallbackSettings($tenantId));
        }

        return static::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            static::defaults($tenantId)
        );
    }

    public static function persistForTenant(string $tenantId, array $attributes): self
    {
        $payload = array_merge(static::defaults($tenantId), $attributes, ['tenant_id' => $tenantId]);

        if (!Schema::hasTable('pos_loyalty_settings')) {
            Cache::put(static::cacheKey($tenantId), $payload, now()->addDays(30));

            return new static($payload);
        }

        $settings = static::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            static::defaults($tenantId)
        );
        $settings->fill($attributes);
        $settings->save();

        return $settings;
    }

    private static function readFallbackSettings(string $tenantId): array
    {
        $cached = Cache::get(static::cacheKey($tenantId), []);

        return array_merge(static::defaults($tenantId), is_array($cached) ? $cached : []);
    }

    private static function cacheKey(string $tenantId): string
    {
        return "pos_loyalty_settings_fallback_{$tenantId}";
    }

    public function toPosPayload(): array
    {
        return [
            'enabled' => (bool) $this->enabled,
            'points_per_amount' => (int) $this->points_per_amount,
            'min_spend_amount' => (int) $this->min_spend_amount,
            'max_points_per_order' => $this->max_points_per_order !== null
                ? (int) $this->max_points_per_order
                : null,
            // Redemption: Rp value of one point (0 = redemption off), limits and expiry.
            'redeem_value_per_point' => (int) ($this->redeem_value_per_point ?? 0),
            'min_redeem_points' => (int) ($this->min_redeem_points ?? 0),
            'max_redeem_points_per_order' => $this->max_redeem_points_per_order !== null ? (int) $this->max_redeem_points_per_order : null,
            'points_expire_months' => $this->points_expire_months !== null ? (int) $this->points_expire_months : null,
        ];
    }
}
