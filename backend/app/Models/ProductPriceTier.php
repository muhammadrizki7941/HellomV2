<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceTier extends Model
{
    protected $fillable = ['product_id', 'product_variant_id', 'tenant_id', 'label', 'min_quantity', 'customer_type', 'price'];

    protected function casts(): array
    {
        return ['min_quantity' => 'integer', 'price' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
