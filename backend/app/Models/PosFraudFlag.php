<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A suspicious pattern found by FraudDetector, kept for the owner to review (Fase 4). */
class PosFraudFlag extends Model
{
    protected $fillable = ['organization_id', 'outlet_id', 'rule', 'member_id', 'user_id', 'order_id', 'details', 'status'];

    protected $casts = ['details' => 'array'];
}
