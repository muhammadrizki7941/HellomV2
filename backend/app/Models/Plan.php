<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    public const TYPE_FREE = 'free';
    public const TYPE_SUBSCRIPTION = 'subscription';
    public const TYPE_ONE_TIME = 'one_time';
    public const TYPE_LIFETIME = 'lifetime';

    public const BILLING_MONTHLY = 'monthly';
    public const BILLING_YEARLY = 'yearly';

    protected $fillable = [
        'slug',
        'name',
        'type',
        'price',
        'is_active',
        'description',
        'features',
        'billing_cycles',
        'duration_days',
        'max_outlets',
        'is_visible',
        'is_recommended',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_active' => 'boolean',
            'features' => 'array',
            'billing_cycles' => 'array',
            'duration_days' => 'integer',
            'max_outlets' => 'integer',
            'is_visible' => 'boolean',
            'is_recommended' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isLifetime(): bool
    {
        return $this->type === self::TYPE_LIFETIME;
    }

    public function isFree(): bool
    {
        return $this->type === self::TYPE_FREE;
    }

    public function isSubscription(): bool
    {
        return $this->type === self::TYPE_SUBSCRIPTION;
    }

    /**
     * When access bought at $start ends. Single source of truth for every
     * activation/renewal path. null = never (lifetime and free plans).
     *
     * The cycle the buyer actually chose wins; without one we fall back to
     * what the plan offers (yearly only when it supports yearly).
     */
    public function accessEndsAt(CarbonInterface $start, ?string $billingCycle = null): ?CarbonInterface
    {
        if ($this->isLifetime() || $this->isFree()) {
            return null;
        }

        if ($this->duration_days) {
            return $start->copy()->addDays((int) $this->duration_days);
        }

        if ($billingCycle === self::BILLING_YEARLY) {
            return $start->copy()->addYear();
        }

        if ($billingCycle === self::BILLING_MONTHLY) {
            return $start->copy()->addMonth();
        }

        return $this->hasBillingCycle(self::BILLING_YEARLY)
            ? $start->copy()->addYear()
            : $start->copy()->addMonth();
    }

    public function hasBillingCycle(string $cycle): bool
    {
        $cycles = $this->billing_cycles ?? [];
        return in_array($cycle, $cycles, true);
    }

    public function getEffectivePrice(string $billingCycle = self::BILLING_MONTHLY): int
    {
        // For one_time yearly plans, return the yearly price directly
        if ($this->type === self::TYPE_ONE_TIME && $billingCycle === self::BILLING_YEARLY) {
            return $this->price;
        }

        // For subscription plans with yearly billing, apply discount (e.g., 10 months for 12 months)
        if ($this->type === self::TYPE_SUBSCRIPTION && $billingCycle === self::BILLING_YEARLY) {
            return $this->price * 10; // 2 months free
        }

        return $this->price;
    }
}
