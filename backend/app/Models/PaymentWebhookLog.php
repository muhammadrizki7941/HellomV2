<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Raw webhook as received (append-only; outcome is filled once processing ends). */
class PaymentWebhookLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['provider', 'event_id', 'reference', 'signature_valid', 'outcome', 'error', 'headers', 'payload', 'ip', 'received_at'];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'headers' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
