<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'landing_page_id',
        'block_key',
        'block_type',
        'sort_order',
        'is_visible',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
            'content' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function landingPage(): BelongsTo
    {
        return $this->belongsTo(OrganizationLandingPage::class, 'landing_page_id');
    }
}
