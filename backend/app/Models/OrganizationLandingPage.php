<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationLandingPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'title',
        'slug',
        'status',
        'content',
        'published_at',
        'is_home',
        'seo_title',
        'seo_description',
        'seo_image',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'published_at' => 'datetime',
            'draft_document' => 'array',
            'draft_revision' => 'integer',
            'draft_saved_at' => 'datetime',
            'published_version_id' => 'integer',
            'is_home' => 'boolean',
        ];
    }

    /** The live snapshot the public page renders (Fase 4: draft and published are separate). */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(LandingPageVersion::class, 'published_version_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
