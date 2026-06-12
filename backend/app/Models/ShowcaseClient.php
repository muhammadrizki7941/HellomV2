<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShowcaseClient extends Model
{
    protected $fillable = [
        'name',
        'logo_url',
        'website_url',
        'sort_order',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }
}
