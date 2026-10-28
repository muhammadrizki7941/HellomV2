<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformMarketingEvent extends Model
{
    protected $guarded = [];
    protected function casts(): array
    {
        return ['context' => 'encrypted:array', 'amount' => 'integer', 'is_test' => 'boolean', 'sent_at' => 'datetime'];
    }
}
