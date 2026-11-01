<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'unit_price',
        'base_unit_price',
        'options_total',
        'qty',
        'line_total',
        'selected_options',
        'product_variant_id',
        'cost_price',
        'product_serial_id',
        'serial_number',
        'warranty_expires_at',
        'client_uuid',
        'created_at_client',
    ];

    protected $casts = [
        'unit_price' => 'integer',
        'base_unit_price' => 'integer',
        'options_total' => 'integer',
        'qty' => 'integer',
        'line_total' => 'integer',
        'selected_options' => 'array',
        'product_variant_id' => 'integer',
        'cost_price' => 'integer',
        'warranty_expires_at' => 'date',
        'created_at_client' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class);
    }
}
