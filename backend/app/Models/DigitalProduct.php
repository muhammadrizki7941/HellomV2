<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DigitalProduct extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'name',
        'tagline',
        'description',
        'category',
        'type',
        'price',
        'currency',
        'thumbnail_url',
        'banner_url',
        'banner_mobile_url',
        'preview_images',
        'tech_stack',
        'tags',
        'is_published',
        'is_featured',
        'is_flagship',
        'flagship_app',
        'sort_order',
        'total_purchases',
        'total_downloads',
    ];

    protected $casts = [
        'preview_images' => 'array',
        'tech_stack' => 'array',
        'tags' => 'array',
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'is_flagship' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(DigitalProductFile::class, 'product_id');
    }

    public function docs(): HasMany
    {
        return $this->hasMany(DigitalProductDoc::class, 'product_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(ProductPurchase::class, 'product_id');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /** Flagship SaaS apps live on /aplikasi, not in the regular /produk catalog. */
    public function scopeFlagship($query, bool $flagship = true)
    {
        return $query->where('is_flagship', $flagship);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function getIsFreeAttribute(): bool
    {
        return (int) $this->price === 0;
    }

    public function getFormattedPriceAttribute(): string
    {
        return 'Rp ' . number_format((int) $this->price, 0, ',', '.');
    }
}
