<?php

namespace App\Models\Hpp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A shopping trip (belanja bahan): updates ingredient prices and stock. */
class HppPurchase extends Model
{
    protected $table = 'hpp_purchases';

    protected $guarded = ['id'];

    protected $casts = ['purchased_at' => 'date', 'total' => 'integer', 'outlet_id' => 'integer'];

    public function items(): HasMany
    {
        return $this->hasMany(HppPurchaseItem::class, 'purchase_id');
    }
}
