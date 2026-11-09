<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandingAboutSetting extends Model
{
    protected $fillable = [
        'demo_video_enabled',
        'demo_video_url',
        'demo_video_title',
        'demo_video_description',
        'title',
        'subtitle',
        'description',
        'years_experience',
        'projects_completed',
        'happy_clients',
        'support_label',
        'products_label',
        'products_heading',
        'products_description',
        'products_cta_label',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'demo_video_enabled' => 'boolean',
            'years_experience' => 'integer',
            'projects_completed' => 'integer',
            'happy_clients' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
