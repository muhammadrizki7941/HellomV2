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
