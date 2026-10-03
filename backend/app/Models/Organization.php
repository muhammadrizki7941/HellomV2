<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'default_locale',
        'status',
        'pos_tenant_slug',
        'pos_tenant_name',
        'pos_provisioned_at',
        'max_outlets_override',
        'logo_path',
        'banner_path',
        'address',
        'phone',
        'email',
        'description',
        'website',
    ];

    protected function casts(): array
    {
        return [
            'pos_provisioned_at' => 'datetime',
            'max_outlets_override' => 'integer',
            'landing_suspended_at' => 'datetime', // Hellom Page shop switched off by super admin
            'landing_shipping' => 'array', // { origin: { id, label }, couriers: [...] } for courier rates
        ];
    }

    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class)->orderBy('sort_order')->orderBy('id');
    }

    public function primaryOutlet(): HasOne
    {
        return $this->hasOne(Outlet::class)->where('is_primary', true);
    }

    protected static function booted(): void
    {
        // The slug doubles as the Hellom Page address until a username is chosen, so it
        // may not be a reserved word (/login, /admin…) or another shop's username.
        static::creating(function (Organization $organization): void {
            $base = (string) $organization->slug;
            if ($base === '') {
                return;
            }
            $slug = $base;
            for ($i = 2; in_array($slug, config('landing.reserved_usernames', []), true)
                || static::query()->where('slug', $slug)->orWhere('landing_username', $slug)->exists(); $i++) {
                $slug = $base . '-' . $i;
            }
            $organization->slug = $slug;
        });
    }

    /** Hellom Page public address: hellomspace.com/{landing username}; the org slug stays for POS links. */
    public function landingUsername(): string
    {
        return (string) ($this->landing_username ?: $this->slug);
    }

    /** Hellom Page: visitor reports about this seller. */
    public function landingReports(): HasMany
    {
        return $this->hasMany(LandingReport::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(OrganizationWallet::class);
    }
}
