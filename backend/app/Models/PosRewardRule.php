<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosRewardRule extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'trigger_type',
        'trigger_value',
        'reward_type',
        'reward_value',
        'reward_product_id',
        'is_active',
        'description',
    ];

    protected $casts = [
        'trigger_value' => 'integer',
        'reward_value' => 'integer',
        'is_active' => 'boolean',
    ];

    public function rewardProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'reward_product_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PosRedemption::class, 'reward_rule_id');
    }

    public function getTriggerLabelAttribute(): string
    {
        return match($this->trigger_type) {
            'points_threshold' => "Kumpul {$this->trigger_value} poin",
            'orders_threshold' => "Beli {$this->trigger_value}x",
            'spend_threshold' => "Total belanja > Rp " . number_format($this->trigger_value, 0, ',', '.'),
            default => $this->trigger_type,
        };
    }

    public function getRewardLabelAttribute(): string
    {
        return match($this->reward_type) {
            'free_product' => 'Produk gratis',
            'discount_percent' => "Diskon {$this->reward_value}%",
            'discount_fixed' => "Diskon Rp " . number_format($this->reward_value, 0, ',', '.'),
            'bonus_points' => "Bonus {$this->reward_value} poin",
            default => $this->reward_type,
        };
    }
}