<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageItem extends Model
{
    protected $fillable = [
        'package_product_id',
        'item_product_id',
        'qty',
        'sort_order',
    ];

    protected $casts = [
        'qty' => 'integer',
        'sort_order' => 'integer',
    ];

    public function packageProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'package_product_id');
    }

    public function itemProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'item_product_id');
    }
}
