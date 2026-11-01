<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosSyncChange extends Model
{
    protected $fillable = ['tenant_id', 'outlet_id', 'entity_type', 'entity_id', 'operation'];
}
