<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPageStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'landing_page_id',
        'views_count',
        'last_viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'views_count' => 'integer',
            'last_viewed_at' => 'datetime',
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
