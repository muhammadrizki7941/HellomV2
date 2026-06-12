<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingDomain extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'landing_page_id',
        'domain',
        'is_primary',
        'status',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
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
