<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportCannedResponse extends Model
{
    protected $fillable = ['title', 'category', 'body', 'is_active', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
