<?php

namespace App\Models\Hpp;

use Illuminate\Database\Eloquent\Model;

/**
 * A size or modifier of a product (Large, Extra shot, Topping keju). Its extra ingredients are
 * recipe items with variant_id, per ONE unit; linked to a POS option value so a sale of
 * "Es Kopi + Extra shot" also takes the extra coffee from stock.
 */
class HppProductVariant extends Model
{
    protected $table = 'hpp_product_variants';

    protected $guarded = ['id'];

    protected $casts = ['price_delta' => 'integer', 'pos_option_value_id' => 'integer', 'sort_order' => 'integer'];
}
