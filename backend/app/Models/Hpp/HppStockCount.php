<?php

namespace App\Models\Hpp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Stok opname (Pro): counted quantities at a moment, the base of theoretical-vs-actual usage. */
class HppStockCount extends Model
{
    protected $table = 'hpp_stock_counts';

    protected $guarded = ['id'];

    protected $casts = ['counted_at' => 'datetime', 'outlet_id' => 'integer'];

    public function items(): HasMany
    {
        return $this->hasMany(HppStockCountItem::class, 'count_id');
    }
}
