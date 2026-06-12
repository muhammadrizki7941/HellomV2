<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SitePromotion extends Model
{
    protected $fillable = [
        'tenant_id',
        'title',
        'promo_code',
        'slug',
        'description',
        'terms',
        'thumbnail_path',
        'link_url',
        'bonus_points',
        'minimum_spend',
        'claim_limit',
        'claimed_count',
        'requires_reservation',
        'starts_at',
        'ends_at',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'bonus_points' => 'integer',
        'minimum_spend' => 'integer',
        'claim_limit' => 'integer',
        'claimed_count' => 'integer',
        'requires_reservation' => 'boolean',
    ];

    public function claims()
    {
        return $this->hasMany(SitePromotionClaim::class);
    }

    public function thumbnailUrl(): ?string
    {
        if (!$this->thumbnail_path) return null;
        $path = trim((string) $this->thumbnail_path);
        $publicBase = '/' . trim((string) config('filesystems.disks.public.url', '/storage'), '/');

        if (Str::startsWith($path, ['http://', 'https://', '//'])) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            if (is_string($parsedPath) && Str::contains($parsedPath, '/storage/')) {
                return $publicBase . '/' . ltrim(Str::after($parsedPath, '/storage/'), '/');
            }
            if (is_string($parsedPath) && Str::contains($parsedPath, '/media/')) {
                return $publicBase . '/' . ltrim(Str::after($parsedPath, '/media/'), '/');
            }

            return $path;
        }

        $path = ltrim($path, '/');
        if (Str::startsWith($path, 'storage/')) {
            $path = Str::after($path, 'storage/');
        }
        if (Str::startsWith($path, 'media/')) {
            $path = Str::after($path, 'media/');
        }

        $url = $publicBase . '/' . ltrim($path, '/');
        $version = (string) ($this->updated_at?->timestamp ?? '');
        if ($version !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . $version;
        }

        return $url;
    }

    public function linkHref(): ?string
    {
        $url = trim((string) ($this->link_url ?? ''));
        if ($url === '') return null;

        // Allow internal relative links like "/promo/1".
        if (Str::startsWith($url, '/')) return $url;

        // External links.
        if (Str::startsWith($url, ['http://', 'https://'])) return $url;

        // If user typed without scheme, best-effort assume https.
        return 'https://' . $url;
    }

    public function scopeActiveForCustomer(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }

    protected static function boot()
    {
        parent::boot();

        // Multi-tenancy scope
        static::addGlobalScope('tenant', function ($builder) {
            $user = auth()->user();
            if ($user && $user->currentOrganization) {
                $builder->where('tenant_id', static::resolveTenantIdentifier($user->currentOrganization));
            }
        });

        static::creating(function ($promo) {
            $user = auth()->user();
            if ($user && $user->currentOrganization && !$promo->tenant_id) {
                $promo->tenant_id = static::resolveTenantIdentifier($user->currentOrganization);
            }
        });
    }

    public static function tenantColumnUsesString(): bool
    {
        if (!Schema::hasColumn('site_promotions', 'tenant_id')) {
            return true;
        }

        $type = strtolower((string) Schema::getColumnType('site_promotions', 'tenant_id'));

        return in_array($type, ['string', 'varchar', 'char', 'text'], true);
    }

    public static function resolveTenantIdentifier(?Organization $organization): string|int|null
    {
        if (!$organization) {
            return null;
        }

        if (static::tenantColumnUsesString()) {
            return (string) ($organization->pos_tenant_slug ?: $organization->slug);
        }

        return (int) $organization->id;
    }
}
