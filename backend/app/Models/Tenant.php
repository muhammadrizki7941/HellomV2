<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'plan',
        'status',
        'trial_started_at',
        'active_until',
        'subdomain',
        'custom_domain',
    ];

    protected $casts = [
        'trial_started_at' => 'date',
        'active_until' => 'date',
    ];
}
