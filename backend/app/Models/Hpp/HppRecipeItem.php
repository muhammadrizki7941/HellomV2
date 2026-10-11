<?php

namespace App\Models\Hpp;

use Illuminate\Database\Eloquent\Model;

/** One line of a recipe: an ingredient/packaging or a sub-recipe (component_type "product"). */
class HppRecipeItem extends Model
{
    protected $table = 'hpp_recipe_items';

    protected $guarded = ['id'];

    protected $casts = ['qty' => 'float', 'component_id' => 'integer', 'variant_id' => 'integer', 'sort_order' => 'integer'];
}
