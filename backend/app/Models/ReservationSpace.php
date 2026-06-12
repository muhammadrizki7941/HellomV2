<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class ReservationSpace extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'location',
        'capacity',
        'description',
        'rent_price',
        'rent_enabled',
        'min_menu_total',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'rent_price' => 'integer',
        'rent_enabled' => 'boolean',
        'min_menu_total' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function images(): HasMany
    {
        return $this->hasMany(ReservationSpaceImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReservationSpaceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function getItemsTotalAttribute(): int
    {
        return (int) $this->items->sum(fn ($it) => (int) $it->qty * (int) $it->unit_price);
    }

    public function getTotalPriceAttribute(): int
    {
        $rent = $this->rent_enabled ? (int) $this->rent_price : 0;
        return $rent + (int) $this->items_total;
    }

    public function coverImageUrl(): ?string
    {
        $img = $this->images()->orderBy('sort_order')->orderBy('id')->first();
        return $img?->url();
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($space) {
            // Cascade delete images and items
            $space->images()->delete();
            $space->items()->delete();
        });
    }
}
