<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSerial extends Model
{
    protected $fillable = [
        'tenant_id', 'outlet_id', 'product_id', 'product_variant_id', 'serial_number', 'status', 'sold_order_id', 'sold_at',
    ];

    protected function casts(): array
    {
        return ['sold_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function soldOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'sold_order_id');
    }
}
