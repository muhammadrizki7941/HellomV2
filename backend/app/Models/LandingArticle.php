<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandingArticle extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'thumbnail',
        'content',
        'excerpt',
        'category',
        'published_at',
        'read_time',
        'is_featured',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'read_time' => 'integer',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
