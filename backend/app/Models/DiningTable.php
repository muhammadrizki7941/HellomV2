<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiningTable extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'public_id',
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    protected static function boot()
    {
        parent::boot();

        // Multi-tenancy scope
        static::addGlobalScope('tenant', function ($builder) {
            $user = auth()->user();
            if ($user && $user->currentOrganization) {
                $tenantSlug = $user->currentOrganization->pos_tenant_slug
                    ?? $user->currentOrganization->slug;
                $builder->where('tenant_id', $tenantSlug);
            }
        });

        static::creating(function ($table) {
            if (!$table->tenant_id) {
                $user = auth()->user();
                if ($user && $user->currentOrganization) {
                    $table->tenant_id = $user->currentOrganization->pos_tenant_slug
                        ?? $user->currentOrganization->slug;
                }
            }
            if (!$table->public_id) {
                $table->public_id = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12));
            }
        });
    }
}
