<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class LoyaltySetting extends Model
{
    protected $fillable = [
        'enabled',
        'earn_method',
        'points_per_1000',
        'points_unit_amount',
        'points_per_unit',
        'min_spend_amount',
        'points_per_min_spend',
        'flat_points_per_order',
        'max_points_per_order',
        'redeem_enabled',
        'redeem_rp_per_point',
        'redeem_min_spend_amount',
        'redeem_max_points_per_order',
        'redeem_max_discount_rp',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'earn_method' => 'string',
        'points_per_1000' => 'integer',
        'points_unit_amount' => 'integer',
        'points_per_unit' => 'integer',
        'min_spend_amount' => 'integer',
        'points_per_min_spend' => 'integer',
        'flat_points_per_order' => 'integer',
        'max_points_per_order' => 'integer',
        'redeem_enabled' => 'boolean',
        'redeem_rp_per_point' => 'integer',
        'redeem_min_spend_amount' => 'integer',
        'redeem_max_points_per_order' => 'integer',
        'redeem_max_discount_rp' => 'integer',
    ];

    public static function current(): ?self
    {
        if (!Schema::hasTable('loyalty_settings')) {
            return null;
        }

        $cacheKey = 'loyalty_settings.current';

        return Cache::rememberForever($cacheKey, function () {
            $row = static::query()->first();
            if ($row) {
                return $row;
            }

            return static::query()->create([
                'enabled' => true,
                'earn_method' => 'per_1000',
                'points_per_1000' => 1,
                'points_unit_amount' => 1000,
                'points_per_unit' => 1,
                'min_spend_amount' => 0,
                'points_per_min_spend' => 0,
                'flat_points_per_order' => 0,
                'max_points_per_order' => null,
                'redeem_enabled' => false,
                'redeem_rp_per_point' => 0,
                'redeem_min_spend_amount' => 0,
                'redeem_max_points_per_order' => null,
                'redeem_max_discount_rp' => null,
            ]);
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget('loyalty_settings.current');
    }
}
