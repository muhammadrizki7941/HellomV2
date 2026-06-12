<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLandingpage extends Model
{
    use HasFactory;

    protected $table = 'customer_landingpage';

    protected $fillable = [
        'organization_id',
        'landing_page_id',
        'block_id',
        'form_title',
        'name',
        'phone',
        'email',
        'fields',
        'source_url',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
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
