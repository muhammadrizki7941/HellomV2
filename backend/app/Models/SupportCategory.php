<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportCategory extends Model
{
    protected $fillable = ['slug', 'name', 'default_priority', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
