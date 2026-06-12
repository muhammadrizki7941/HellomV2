<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandingAboutSetting extends Model
{
    protected $fillable = [
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
            'years_experience' => 'integer',
            'projects_completed' => 'integer',
            'happy_clients' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
