<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A table (or the outlet's "counter" pseudo-table for orders from the shop link).
 * `public_id` is the QR token: random, bound to one outlet (tenant_id/outlet_id) and
 * rotatable by the owner — a rotated token stops working immediately.
 */
class DiningTable extends Model
{
    use HasFactory;

    public const KIND_TABLE = 'table';
    public const KIND_COUNTER = 'counter';

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'public_id',
        'code',
        'name',
        'kind',
        'is_active',
        'token_rotated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'token_rotated_at' => 'datetime',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** 24 lowercase letters/digits (~124 bits): impossible to guess or enumerate. */
    public static function newPublicToken(): string
    {
        do {
            $token = Str::lower(Str::random(24));
        } while (static::withoutGlobalScope('tenant')->where('public_id', $token)->exists());

        return $token;
    }

    /** Tokens from old seeders/imports (e.g. "table00001", "table-1") can be guessed. */
    public function hasWeakToken(): bool
    {
        $token = (string) $this->public_id;

        return strlen($token) < 12 || str_starts_with($token, 'table') || !preg_match('/[a-z]/', $token) || !preg_match('/\d/', $token);
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
                $table->public_id = static::newPublicToken();
            }
        });
    }
}
