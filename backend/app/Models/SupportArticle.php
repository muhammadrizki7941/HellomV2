<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportArticle extends Model
{
    protected $fillable = [
        'title', 'slug', 'category', 'locale', 'tags', 'route_path', 'content_markdown',
        'content_hash', 'status', 'version', 'created_by_user_id', 'updated_by_user_id', 'published_at',
    ];

    protected function casts(): array
    {
        return ['tags' => 'array', 'published_at' => 'datetime'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
