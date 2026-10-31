<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionCheckoutPayment extends Model
{
    protected $fillable = [
        'checkout_intent_id',
        'invoice_id',
        'provider',
        'reference_id',
        'transaction_id',
        'session_id',
        'method',
        'channel',
        'channel_label',
        'status',
        'amount',
        'payment_number',
        'qr_string',
        'qr_image_url',
        'expires_at',
        'paid_at',
        'gateway_metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'gateway_metadata' => 'array',
        ];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(CheckoutIntent::class, 'checkout_intent_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
