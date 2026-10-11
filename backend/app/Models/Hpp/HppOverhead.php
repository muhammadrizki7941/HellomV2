<?php

namespace App\Models\Hpp;

use Illuminate\Database\Eloquent\Model;

/** Monthly fixed cost (sewa, listrik, gaji, …). outlet_id 0 = whole business. */
class HppOverhead extends Model
{
    protected $table = 'hpp_overheads';

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'integer', 'outlet_id' => 'integer', 'is_active' => 'boolean'];

    public const CATEGORIES = ['rent', 'utility', 'salary', 'subscription', 'depreciation', 'other'];
}
