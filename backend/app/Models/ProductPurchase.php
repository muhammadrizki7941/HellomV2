<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPurchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'transaction_code',
        'amount_paid',
        'payment_method',
        'payment_status',
        'payment_gateway',
        'gateway_ref',
        'checkout_url',
        'payment_instructions',
        'paid_at',
        'download_count',
        'last_downloaded_at',
        'expires_at',
    ];

    protected $casts = [
        'payment_instructions' => 'array',
        'paid_at' => 'datetime',
        'last_downloaded_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(DigitalProduct::class, 'product_id');
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->gt($this->expires_at);
    }

    public function hasAccess(): bool
    {
        return $this->payment_status === 'paid' && !$this->isExpired();
    }
}
