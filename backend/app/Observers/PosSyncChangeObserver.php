<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\PosMember;
use App\Models\PosSyncChange;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;

class PosSyncChangeObserver
{
    public function deleted(Model $model): void
    {
        $type = match (true) {
            $model instanceof Product => 'product',
            $model instanceof Category => 'category',
            $model instanceof ProductVariant => 'variant',
            $model instanceof ProductSerial => 'serial',
            $model instanceof PosMember => 'member',
            $model instanceof DiningTable => 'table',
            default => null,
        };
        $tenantId = (string) ($model->tenant_id ?? '');
        if ($type === null || $tenantId === '') {
            return;
        }

        PosSyncChange::query()->create([
            'tenant_id' => $tenantId,
            'outlet_id' => $model instanceof PosMember ? null : ($model->outlet_id ?? null),
            'entity_type' => $type,
            'entity_id' => (int) $model->getKey(),
            'operation' => 'deleted',
        ]);
    }
}
