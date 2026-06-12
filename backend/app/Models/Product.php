<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $fillable = [
        'tenant_id',
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'image_path',
        'sort_order',
        'is_available',
        'track_stock',
        'stock',
        'is_package',
        'show_as_banner',
        'banner_title',
        'banner_subtitle',
        'banner_starts_at',
        'banner_ends_at',
        // Purchase configuration
        'available_purchase_types',
        'preorder_enabled',
        'preorder_lead_time_minutes',
        'hide_when_unavailable',
    ];

    protected $casts = [
        'price' => 'integer',
        'sort_order' => 'integer',
        'is_available' => 'boolean',
        'track_stock' => 'boolean',
        'stock' => 'integer',
        'is_package' => 'boolean',
        'show_as_banner' => 'boolean',
        'banner_starts_at' => 'datetime',
        'banner_ends_at' => 'datetime',
        'available_purchase_types' => 'array',
        'preorder_enabled' => 'boolean',
        'preorder_lead_time_minutes' => 'integer',
        'hide_when_unavailable' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)
            ->withTimestamps();
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class);
    }

    public function packageItems(): HasMany
    {
        return $this->hasMany(PackageItem::class, 'package_product_id')
            ->orderBy('sort_order')
            ->orderBy('id');
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

        static::creating(function ($product) {
            if (!$product->tenant_id) {
                $user = auth()->user();
                if ($user && $user->currentOrganization) {
                    $product->tenant_id = $user->currentOrganization->pos_tenant_slug
                        ?? $user->currentOrganization->slug;
                }
            }
        });

        static::deleting(function ($product) {
            // Cascade delete product options and their values
            $product->options()->each(function ($option) {
                $option->values()->delete();
            });
            $product->options()->delete();

            // Delete image if exists
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
        });
    }

    public function imageUrl(): ?string
    {
        if (!$this->image_path) {
            return null;
        }

        $path = trim((string) $this->image_path);
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

    public function isAvailableFor(string $serviceType): bool
    {
        // If hide_when_unavailable is true and product is not available, hide completely
        if ($this->hide_when_unavailable && !$this->is_available) {
            return false;
        }

        // Check specific purchase types if configured
        $allowedTypes = $this->available_purchase_types;
        if (is_array($allowedTypes) && !empty($allowedTypes)) {
            return in_array($serviceType, $allowedTypes, true);
        }

        // Default: available for all types if product is available
        return $this->is_available;
    }

    public function canPreorder(): bool
    {
        if (!$this->preorder_enabled) {
            return false;
        }

        // Check if product is available for pre_order
        return $this->isAvailableFor('pre_order');
    }

    public function getPreorderLeadTime(): int
    {
        return $this->preorder_lead_time_minutes ?? 30;
    }

    public function isAvailableNow(): bool
    {
        if (!$this->is_available) {
            return false;
        }

        // Check stock if tracking is enabled
        if ($this->track_stock && $this->stock <= 0) {
            return false;
        }

        return true;
    }
}
