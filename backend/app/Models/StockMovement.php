<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = [
        'tenant_id', 'outlet_id', 'product_id', 'product_variant_id', 'movement_type', 'qty',
        'reference_type', 'reference_id', 'note', 'created_by', 'created_at_client', 'client_uuid',
    ];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'created_at_client' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
