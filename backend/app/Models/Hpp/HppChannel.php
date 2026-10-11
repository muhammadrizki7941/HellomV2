<?php

namespace App\Models\Hpp;

use App\Support\Hpp\Templates;
use Illuminate\Database\Eloquent\Model;

/** Sales channel with its fee (QRIS MDR, payment gateway, Hellom, ojol commission). */
class HppChannel extends Model
{
    protected $table = 'hpp_channels';

    protected $guarded = ['id'];

    protected $casts = ['fee_pct' => 'float', 'fee_flat' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    /** First visit: the usual channels (kasir, QRIS, Hellom Page, GoFood, GrabFood, ShopeeFood). */
    public static function ensureDefaults(int $organizationId): void
    {
        if (self::query()->where('organization_id', $organizationId)->exists()) {
            return;
        }
        foreach (Templates::CHANNELS as $i => [$name, $kind, $pct, $flat]) {
            self::query()->create(['organization_id' => $organizationId, 'name' => $name, 'kind' => $kind, 'fee_pct' => $pct, 'fee_flat' => $flat, 'sort_order' => $i]);
        }
    }
}
