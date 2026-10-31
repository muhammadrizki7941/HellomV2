<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppCatalog extends Model
{
    use HasFactory;

    protected $table = 'apps';

    protected $fillable = [
        'slug',
        'name',
        'is_active',
        'icon_path',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected $appends = ['icon_url'];

    public function getIconUrlAttribute(): ?string
    {
        return $this->icon_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->icon_path) : null;
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class, 'app_id')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }
}
