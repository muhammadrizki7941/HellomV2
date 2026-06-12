<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShowcasePortfolio extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'description',
        'full_description',
        'video_url',
        'thumbnail_url',
        'gallery_images',
        'client_name',
        'project_year',
        'project_url',
        'category',
        'tech_stack',
        'sort_order',
        'is_published',
        'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'gallery_images' => 'array',
            'tech_stack' => 'array',
        ];
    }
}
