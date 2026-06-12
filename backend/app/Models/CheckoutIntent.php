<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutIntent extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'app_id',
        'plan_id',
        'subscription_id',
        'intent_token',
        'status',
        'amount',
        'currency',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'amount' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(AppCatalog::class, 'app_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
