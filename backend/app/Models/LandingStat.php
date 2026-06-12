<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'first_published_page_id',
        'first_published_at',
        'published_count',
        'views_count',
        'last_viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'first_published_at' => 'datetime',
            'published_count' => 'integer',
            'views_count' => 'integer',
            'last_viewed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function firstPublishedPage(): BelongsTo
    {
        return $this->belongsTo(OrganizationLandingPage::class, 'first_published_page_id');
    }
}
