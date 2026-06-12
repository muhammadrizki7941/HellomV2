<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReservationSpaceImage extends Model
{
    protected $fillable = [
        'reservation_space_id',
        'image_path',
        'caption',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(ReservationSpace::class, 'reservation_space_id');
    }

    public function url(): string
    {
        $path = trim((string) $this->image_path);

        if (empty($path)) {
            return '';
        }

        // Already a full URL, return as-is
        if (Str::startsWith($path, ['http://', 'https://', '//'])) {
            return $path;
        }

        // Strip any existing prefixes
        $path = ltrim($path, '/');
        foreach (['storage/', 'media/', 'public/'] as $prefix) {
            if (Str::startsWith($path, $prefix)) {
                $path = Str::after($path, $prefix);
            }
        }

        // Always use /storage/ — Laravel default symlink path
        $url = url('storage/' . $path);

        // Cache busting
        $version = (string) ($this->updated_at?->timestamp ?? '');
        if ($version !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . $version;
        }

        return $url;
    }
}
