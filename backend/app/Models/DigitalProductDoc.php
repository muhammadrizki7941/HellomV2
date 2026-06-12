<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalProductDoc extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'title',
        'doc_type',
        'content',
        'file_path',
        'video_url',
        'external_url',
        'sort_order',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(DigitalProduct::class, 'product_id');
    }
}
